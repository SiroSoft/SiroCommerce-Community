<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/EvidenceStatus.php';

use App\Support\EvidenceStatus;

/** Offline, non-destructive backup/restore and failure-proof evidence collector. */
$backend = dirname(__DIR__);
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $args[$key] = $value;
    }
}
$out = $args['out'] ?? ($backend . '/storage/evidence/' . gmdate('Ymd\THis\Z'));
$db = $args['db'] ?? ($backend . '/storage/database.sqlite');
$uploads = $args['uploads'] ?? ($backend . '/storage/uploads');
$config = $args['config'] ?? ($backend . '/config');
$migrations = $args['migrations'] ?? ($backend . '/database/migrations');
$version = $args['version'] ?? ($backend . '/app/CommerceVersion.php');
$rpoTarget = (int) ($args['rpo-seconds'] ?? 900);
$rtoTarget = (int) ($args['rto-seconds'] ?? 3600);
$policy = is_file($backend . '/config/production-proof.php') ? require $backend . '/config/production-proof.php' : [];

if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
    fwrite(STDERR, "production proof: cannot create output directory\n");
    exit(1);
}
$started = microtime(true);
$status = 'PASS';
$notes = [];
$artifacts = [];
$externalBlockers = [];
$collect = static function (string $class, string $path) use (&$artifacts, &$status, &$notes, &$externalBlockers): void {
    if (!is_file($path) && !is_dir($path)) {
        $status = 'BLOCKED_EXTERNAL';
        $externalBlockers[] = EvidenceStatus::blockedExternal($class . ' unavailable.', 'Release Operations', 'Readable ' . $class . ' at ' . $path, 'php scripts/production-proof.php');
        $notes[] = $externalBlockers[array_key_last($externalBlockers)]['reason'];
        return;
    }
    $paths = is_file($path) ? [$path] : [];
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) if ($item->isFile()) $paths[] = $item->getPathname();
        sort($paths);
    }
    foreach ($paths as $file) {
        $hash = hash_file('sha256', $file);
        if (is_string($hash)) $artifacts[] = ['class' => $class, 'path' => str_replace('\\', '/', $file), 'bytes' => filesize($file), 'sha256' => $hash];
    }
};
$collect('uploads', $uploads);
$collect('config', $config);
$collect('config', $backend . '/.env.example');
$collect('schema', $migrations);
$collect('version', $version);

$dbEvidence = ['status' => 'BLOCKED_EXTERNAL', 'source' => $db, 'row_counts' => [], 'invariants' => []];
if (!is_file($db) || filesize($db) === 0) {
    $externalBlockers[] = EvidenceStatus::blockedExternal('Database unavailable; no restore was claimed.', 'Database Operations', 'Readable database backup or runtime database at ' . $db, 'php scripts/production-proof.php --db=' . $db);
    $notes[] = $externalBlockers[array_key_last($externalBlockers)]['reason'];
} elseif (($args['driver'] ?? 'sqlite') !== 'sqlite') {
    $notes[] = 'live MySQL restore needs an isolated server and credentials; not available to this run';
} elseif (!extension_loaded('pdo_sqlite')) {
    $notes[] = 'pdo_sqlite is unavailable; database restore rehearsal was not run';
} else {
    $stage = $out . '/staged/database.sqlite';
    if (!is_dir(dirname($stage))) mkdir(dirname($stage), 0775, true);
    if (!copy($db, $stage)) {
        $notes[] = 'database staging copy failed';
    } else {
        try {
            $pdo = new PDO('sqlite:' . $stage, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1) $dbEvidence['row_counts'][$table] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
            }
            if (isset($dbEvidence['row_counts']['inventory_items'])) {
                $bad = (int) $pdo->query('SELECT COUNT(*) FROM inventory_items WHERE on_hand < 0 OR reserved < 0 OR reserved > on_hand')->fetchColumn();
                $dbEvidence['invariants']['inventory_non_negative_and_reserved_bounded'] = $bad === 0;
            }
            $dbEvidence['status'] = 'PASS';
            $dbEvidence['staged_sha256'] = hash_file('sha256', $stage);
        } catch (Throwable $e) {
            $dbEvidence['error'] = get_class($e) . ': ' . $e->getMessage();
            $notes[] = 'staged database verification failed';
        }
    }
}
if ($dbEvidence['status'] !== 'PASS') {
    // A missing runtime database is an unavailable prerequisite, not a failed
    // restore claim. Copy/verification errors below remain real failures.
    if (!isset($dbEvidence['error']) && (!is_file($db) || filesize($db) === 0)) $status = 'BLOCKED_EXTERNAL';
    else $status = 'FAIL';
}
$elapsed = microtime(true) - $started;
$rto = ['measured_seconds' => round($elapsed, 3), 'target_seconds' => $rtoTarget, 'status' => $dbEvidence['status'] === 'PASS' && $elapsed <= $rtoTarget ? 'PASS' : 'NOT_PROVEN'];
$rpo = ['target_seconds' => $rpoTarget, 'status' => 'NOT_PROVEN', 'reason' => 'backup age not supplied'];
if (isset($args['backup-created-at'])) {
    $backupTime = strtotime((string) $args['backup-created-at']);
    if ($backupTime === false) {
        $rpo['status'] = 'FAIL';
        $rpo['reason'] = 'backup age is not a valid UTC ISO-8601 timestamp';
    $status = 'BLOCKED_EXTERNAL';
    } else {
        $age = strtotime(gmdate('c')) - $backupTime;
        $rpo['measured_seconds'] = $age;
        $rpo['status'] = $age >= 0 && $age <= $rpoTarget ? 'PASS' : 'FAIL';
        if ($rpo['status'] === 'FAIL') $status = 'BLOCKED';
    }
}
$drills = [];
foreach (['F01 provider timeout/unknown', 'F02 duplicate webhook', 'F03 shipping timeout', 'F04 worker outage', 'F05 cache outage', 'F06 mail outage', 'F07 database deadlock', 'F08 duplicate checkout', 'F09 inventory race', 'F10 extension failure', 'F11 upgrade rollback'] as $name) {
    $drills[] = ['name' => $name, 'status' => 'NOT_RUN', 'reason' => 'requires isolated dependency or concurrency injection'];
}
$evidence = [
    'evidence_version' => 1,
    'status' => $status,
    'generated_at' => gmdate('c'),
    'release' => ['run_id' => basename(rtrim($out, DIRECTORY_SEPARATOR)), 'commit' => getenv('RELEASE_COMMIT') ?: 'unknown', 'actor' => getenv('RELEASE_ACTOR') ?: (getenv('GITHUB_ACTOR') ?: (getenv('USERNAME') ?: (getenv('USER') ?: 'unknown')))],
    'runtime' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'pdo_sqlite' => extension_loaded('pdo_sqlite')],
    'command' => array_values($argv),
    'policy' => $policy,
    'evidence_boundary' => 'Offline rehearsal only; infrastructure encryption, immutability, retention, and live restore are not proven.',
    'source' => ['database' => $db, 'uploads' => $uploads, 'config' => $config, 'schema' => $migrations, 'version' => $version],
    'artifacts' => $artifacts,
    'database_restore_rehearsal' => $dbEvidence,
    'rpo' => $rpo,
    'rto' => $rto,
    'drills' => $drills,
    'notes' => $notes,
    'external_blockers' => $externalBlockers,
];
$manifest = $out . '/manifest.json';
$written = file_put_contents($manifest, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if ($written === false) {
    fwrite(STDERR, "production proof: cannot write manifest\n");
    exit(1);
}
echo 'production proof: ' . $status . ' manifest=' . $manifest . ' elapsed=' . round($elapsed, 3) . "s\n";
exit($status === 'PASS' ? 0 : ($status === 'BLOCKED_EXTERNAL' ? 2 : 1));
