<?php

declare(strict_types=1);

/** Static release gate for controls that can be proven without production credentials. */
$base = dirname(__DIR__);
$failures = [];
$env = (string) file_get_contents($base . '/.env.example');
if (str_contains($env, 'APP_ENV=production')) $failures[] = '.env.example must remain non-production';
if (preg_match('/JWT_SECRET=(change-me|please_set|your_secret)/i', $env) !== 1) $failures[] = 'JWT_SECRET placeholder must be present in the example';
$routes = (string) file_get_contents($base . '/routes/api.php');
foreach (['/api/checkout/place', '/api/headless/graphql', '/api/auth/login'] as $route) {
    if (!str_contains($routes, $route)) $failures[] = 'required route missing: ' . $route;
}
foreach (['engineering/08_SECURITY_SIDE_EFFECTS.md', 'engineering/09_PERFORMANCE_BUDGETS.md', 'qa/11_QA_AUDIT.md', 'operations/PRODUCTION_OPERATIONS.md'] as $doc) {
    if (!is_file($base . '/../docs/' . $doc)) $failures[] = 'required control document missing: ' . $doc;
}
foreach (['perf/README.md', 'perf/k6/production-proof.js'] as $path) {
    if (!is_file($base . '/../' . $path)) $failures[] = 'required performance proof file missing: ' . $path;
}
$benchSource = (string) file_get_contents($base . '/scripts/bench.php');
foreach (['schema_version\' => 2', "'preflight'", "'latency'", "'errors'", "'db_queries'", "'NOT_PROVEN'"] as $marker) {
    if (!str_contains($benchSource, $marker)) $failures[] = 'benchmark evidence contract marker missing: ' . $marker;
}
foreach (['production-proof.php', 'backup-restore-preflight.php', 'mysql-concurrency-proof.php', 'provider-preflight.php', 'provider-live-smoke.php', 'bench.php'] as $script) {
    $path = $base . '/scripts/' . $script;
    if (!is_file($path)) { $failures[] = 'required proof script missing: ' . $script; continue; }
    $output = []; $exit = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path), $output, $exit);
    if ($exit !== 0) $failures[] = 'proof script has syntax errors: ' . $script;
}
$certificationSchema = $base . '/config/production-certification.schema.json';
if (!is_file($certificationSchema)) $failures[] = 'production certification schema missing';
$certificationChecker = $base . '/scripts/check-certification.php';
if (!is_file($certificationChecker)) $failures[] = 'production certification checker missing';
else {
    $output = []; $exit = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($certificationChecker), $output, $exit);
    if ($exit !== 0) $failures[] = 'certification checker has syntax errors';
}
$policyPath = $base . '/config/production-proof.php';
if (!is_file($policyPath)) {
    $failures[] = 'production proof policy missing: config/production-proof.php';
} else {
    $policy = require $policyPath;
    foreach (['backup_retention_days', 'evidence_retention_days', 'rpo_seconds', 'rto_seconds'] as $key) {
        if (!is_int($policy[$key] ?? null) || $policy[$key] <= 0) $failures[] = 'production proof policy requires positive integer: ' . $key;
    }
    if (($policy['backup_retention_days'] ?? 0) < 35) $failures[] = 'backup retention must be at least 35 days';
    if (($policy['evidence_retention_days'] ?? 0) < 90) $failures[] = 'evidence retention must be at least 90 days';
}
if (!is_file($base . '/../docs/operations/PRODUCTION_EVIDENCE_POLICY.md')) $failures[] = 'required evidence retention policy missing';
$migrationNames = [];
$migrationTimestamps = [];
$migrationFiles = glob($base . '/database/migrations/*.php') ?: [];
sort($migrationFiles, SORT_STRING);
foreach ($migrationFiles as $migration) {
    $name = basename($migration);
    if (preg_match('/^(\d{14})_/', $name, $match) !== 1) {
        $failures[] = 'migration filename must start with a 14-digit timestamp: ' . $name;
    } else {
        $timestamp = $match[1];
        if (isset($migrationTimestamps[$timestamp])) $failures[] = 'duplicate migration timestamp: ' . $timestamp;
        $migrationTimestamps[$timestamp] = $name;
    }
    $source = (string) file_get_contents($migration);
    if (preg_match('/public function up\s*\(/', $source) !== 1) $failures[] = 'migration has no up method: ' . $name;
    if (preg_match('/public function down\s*\(/', $source) !== 1) $failures[] = 'migration has no down method: ' . $name;
    if (preg_match('/public function down\s*\([^)]*\)\s*:\s*void\s*\{\s*\}/s', $source) === 1) $failures[] = 'migration has an empty down method: ' . $name;
    $migrationNames[] = $name;
}
if ($migrationNames !== []) { $sorted = $migrationNames; sort($sorted, SORT_STRING); if ($migrationNames !== $sorted) $failures[] = 'migration directory ordering is not deterministic'; }
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "production proof static checks passed\n");
