<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/EvidenceStatus.php';

use App\Support\EvidenceStatus;

/**
 * Isolated backup/restore and migration rehearsal gate.
 * This command only reads the supplied backup and writes beneath --out.
 */
$backend = dirname(__DIR__);
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[$key] = $value;
    }
}
$manifestPath = (string) ($options['manifest'] ?? '');
$out = (string) ($options['out'] ?? ($backend . '/storage/evidence/backup-restore/' . gmdate('Ymd\THis\Z')));
$migrationDir = (string) ($options['migrations'] ?? ($backend . '/database/migrations'));
$rpoTarget = (int) ($options['rpo-seconds'] ?? 900);
$rtoTarget = (int) ($options['rto-seconds'] ?? 3600);
$started = microtime(true);
$notes = [];
$localFailure = false;
$database = ['status' => 'NOT_RUN'];
$uploads = ['status' => 'NOT_RUN', 'files' => []];
$migration = ['status' => 'NOT_RUN'];
$rpo = ['status' => 'NOT_PROVEN', 'target_seconds' => $rpoTarget];
$external = [];
$fail = static function (string $message, bool $externalBlock = true) use (&$notes, &$external, &$localFailure): void {
    $notes[] = $message;
    if (!$externalBlock) $localFailure = true;
    if ($externalBlock) $external[] = EvidenceStatus::blockedExternal($message, 'Database Operations', 'Verified backup manifest, database, uploads, and restore runtime', 'php scripts/backup-restore-preflight.php --manifest=/path/backup-manifest.json --out=/path/evidence');
};
$outReady = $out !== '' && (is_dir($out) || mkdir($out, 0775, true) || is_dir($out));
$resolve = static function (string $path, string $base): string {
    if ($path === '') return '';
    if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) return $path;
    return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
};
$record = static function (string $path): array {
    return ['path' => str_replace('\\', '/', $path), 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
};
$validRecord = static function (mixed $value): bool {
    return is_array($value) && is_string($value['path'] ?? null) && $value['path'] !== ''
        && is_int($value['bytes'] ?? null) && $value['bytes'] >= 0
        && is_string($value['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $value['sha256']) === 1;
};

if ($manifestPath === '') {
    $fail('backup manifest is required; no restore was claimed');
} elseif (!is_file($manifestPath)) {
    $fail('backup manifest is missing; no restore was claimed');
} elseif (($raw = file_get_contents($manifestPath)) === false || ($manifest = json_decode($raw, true)) === null || !is_array($manifest)) {
    $fail('backup manifest is not valid JSON', false);
} else {
    $manifestBase = dirname($manifestPath);
    $createdAt = $manifest['created_at'] ?? $manifest['backup_created_at'] ?? null;
    $created = is_string($createdAt) ? date_create_immutable($createdAt) : false;
    if ($created === false || !is_string($createdAt) || !str_ends_with(strtoupper($createdAt), 'Z')) {
        $fail('backup manifest requires a UTC created_at timestamp');
    } else {
        $age = time() - $created->getTimestamp();
        $rpo['measured_seconds'] = $age;
        $rpo['status'] = $age >= 0 && $age <= $rpoTarget ? 'PASS' : 'FAIL';
        if ($rpo['status'] !== 'PASS') $fail('backup age is outside the RPO target', false);
    }

    $db = $manifest['database'] ?? null;
    if (!$validRecord($db)) {
        $fail('manifest database record must declare path, bytes, and SHA-256');
    } else {
        $dbPath = $resolve((string) $db['path'], $manifestBase);
        $actual = is_file($dbPath) ? $record($dbPath) : null;
        if ($actual === null || $actual['bytes'] !== $db['bytes'] || !hash_equals(strtolower((string) $db['sha256']), strtolower((string) $actual['sha256']))) {
            $fail('database backup checksum or byte count mismatch', false);
            $database = ['status' => 'FAIL', 'source' => $dbPath];
        } else {
            $stage = $out . '/staged/database.sqlite';
            if (!is_dir(dirname($stage))) mkdir(dirname($stage), 0775, true);
            if (!copy($dbPath, $stage)) $fail('database staging copy failed');
            else $database = ['status' => 'PASS', 'source' => $dbPath, 'staged' => $stage, 'bytes' => $actual['bytes'], 'sha256' => $actual['sha256']];
        }
    }

    $upload = $manifest['uploads'] ?? null;
    $uploadRoot = is_array($upload) ? $resolve((string) ($upload['path'] ?? ''), $manifestBase) : '';
    $declared = is_array($upload) && is_array($upload['files'] ?? null) ? $upload['files'] : null;
    if ($uploadRoot === '' || !is_dir($uploadRoot) || $declared === null) {
        $fail('manifest uploads record must declare an existing path and files list');
    } else {
        $stageRoot = $out . '/staged/uploads';
        $seen = [];
        $uploadFailed = false;
        foreach ($declared as $item) {
            if (!$validRecord($item) || str_contains((string) $item['path'], '..') || str_starts_with((string) $item['path'], '/') || str_starts_with((string) $item['path'], '\\') || preg_match('/^[A-Za-z]:/', (string) $item['path']) === 1) {
                $fail('manifest contains an invalid upload record', false);
                $uploadFailed = true;
                continue;
            }
            $relative = str_replace('/', DIRECTORY_SEPARATOR, (string) $item['path']);
            $source = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
            $seen[$relative] = true;
            $actual = is_file($source) ? $record($source) : null;
            if ($actual === null || $actual['bytes'] !== $item['bytes'] || !hash_equals(strtolower((string) $item['sha256']), strtolower((string) $actual['sha256']))) {
                $fail('upload checksum or byte count mismatch: ' . $item['path'], false);
                $uploadFailed = true;
                continue;
            }
            $destination = $stageRoot . DIRECTORY_SEPARATOR . $relative;
            if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0775, true);
            if (!copy($source, $destination)) { $fail('upload staging copy failed: ' . $item['path'], false); $uploadFailed = true; }
            $uploads['files'][] = $actual;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $relative = substr($item->getPathname(), strlen(rtrim($uploadRoot, DIRECTORY_SEPARATOR)) + 1);
                if (!isset($seen[$relative])) { $fail('upload exists but is absent from manifest: ' . str_replace('\\', '/', $relative), false); $uploadFailed = true; }
            }
        }
        $uploads['status'] = $uploadFailed ? 'FAIL' : 'PASS';
    }
}

if ($database['status'] === 'PASS' && is_dir($migrationDir) && extension_loaded('pdo_sqlite') && is_file($backend . '/vendor/autoload.php')) {
    $failures = [];
    try {
        require_once $backend . '/vendor/autoload.php';
        $files = glob(rtrim($migrationDir, DIRECTORY_SEPARATOR) . '/*.php') ?: [];
        sort($files, SORT_STRING);
        if ($files === []) throw new RuntimeException('no migration files found');
        $migrationDb = $out . '/staged/migration-rehearsal.sqlite';
        if (!is_dir(dirname($migrationDb))) mkdir(dirname($migrationDb), 0775, true);
        $pdo = new PDO('sqlite:' . $migrationDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \Siro\Core\Schema::connect($pdo);
        // Migrations that guard on the driver still resolve Database::connection().
        // Binding the rehearsal connection keeps that guard honest: MySQL-only
        // work becomes a no-op on sqlite instead of an unreplayable failure.
        \Siro\Core\Database::configure(['driver' => 'sqlite', 'database' => $migrationDb], 'default');
        \Siro\Core\Database::default('default');
        $objects = [];
        // Each migration is recorded so a broken rollback names the file instead of
        // surfacing an anonymous "index already exists" error.
        foreach ($files as $file) {
            $object = require $file;
            if (!is_object($object) || !method_exists($object, 'up') || !method_exists($object, 'down')) throw new RuntimeException('migration lacks up/down: ' . basename($file));
            $objects[] = ['name' => basename($file), 'migration' => $object];
        }
        foreach ($objects as $entry) {
            try {
                $entry['migration']->up();
            } catch (Throwable $e) {
                throw new RuntimeException('up failed for ' . $entry['name'] . ': ' . $e->getMessage(), 0, $e);
            }
        }
        foreach (array_reverse($objects) as $entry) {
            try {
                $entry['migration']->down();
            } catch (Throwable $e) {
                $failures[] = ['phase' => 'down', 'migration' => $entry['name'], 'error' => $e->getMessage()];
            }
        }
        foreach ($objects as $entry) {
            try {
                $entry['migration']->up();
            } catch (Throwable $e) {
                $failures[] = ['phase' => 'replay', 'migration' => $entry['name'], 'error' => $e->getMessage()];
                break;
            }
        }
        if ($failures !== []) {
            $names = array_map(static fn (array $failure): string => $failure['migration'], $failures);
            $migration = ['status' => 'FAIL', 'migration_count' => count($objects), 'rehearsal' => 'up -> down(reverse) -> up', 'failures' => $failures];
            $fail('migration upgrade/rollback rehearsal failed: ' . implode(', ', array_unique($names)), false);
        } else {
            $migration = ['status' => 'PASS', 'driver' => 'sqlite', 'migration_count' => count($objects), 'rehearsal' => 'up -> down(reverse) -> up'];
        }
    } catch (Throwable $e) {
        $migration = ['status' => 'FAIL', 'error' => get_class($e) . ': ' . $e->getMessage()];
        $fail('migration upgrade/rollback rehearsal failed', false);
    }
} else {
    if ($database['status'] !== 'PASS') $fail('migration rehearsal skipped because database verification did not pass');
    elseif (!extension_loaded('pdo_sqlite')) $fail('pdo_sqlite is unavailable; migration rehearsal is not proven');
    else $fail('migration directory or Composer autoload is unavailable');
}

$elapsed = microtime(true) - $started;
$rtoPass = $elapsed <= $rtoTarget && $database['status'] === 'PASS' && $uploads['status'] === 'PASS' && $migration['status'] === 'PASS';
$rto = ['status' => $rtoPass ? 'PASS' : 'NOT_PROVEN', 'measured_seconds' => round($elapsed, 3), 'target_seconds' => $rtoTarget];
$status = $localFailure ? 'FAIL' : ($notes === [] && $rpo['status'] === 'PASS' && $rto['status'] === 'PASS' ? 'PASS' : 'BLOCKED_EXTERNAL');
if (!$outReady) { fwrite(STDERR, "cannot create evidence directory\n"); exit(2); }
$evidence = ['evidence_version' => 1, 'status' => $status, 'generated_at' => gmdate('c'), 'release' => ['run_id' => basename(rtrim($out, DIRECTORY_SEPARATOR)), 'commit' => getenv('RELEASE_COMMIT') ?: 'unknown', 'actor' => getenv('RELEASE_ACTOR') ?: (getenv('GITHUB_ACTOR') ?: (getenv('USERNAME') ?: (getenv('USER') ?: 'unknown')))], 'command' => array_values($argv), 'evidence_boundary' => 'Local isolated rehearsal only. This command never proves live MySQL, object storage, encryption, immutability, retention, provider, or production restore.', 'database_restore' => $database, 'uploads_restore' => $uploads, 'rpo' => $rpo, 'rto' => $rto, 'migration_rehearsal' => $migration, 'notes' => array_values(array_unique($notes)), 'external_blockers' => $external];
$written = file_put_contents($out . '/manifest.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if ($written === false) { fwrite(STDERR, "cannot write evidence manifest\n"); exit(2); }
echo 'backup restore preflight: ' . $status . ' manifest=' . $out . '/manifest.json elapsed=' . round($elapsed, 3) . "s\n";
exit($status === 'PASS' ? 0 : ($status === 'BLOCKED_EXTERNAL' ? 2 : 1));
