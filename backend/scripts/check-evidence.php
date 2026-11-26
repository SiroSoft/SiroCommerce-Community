<?php

declare(strict_types=1);

/** Validate release evidence identity and freshness before release sign-off. */
$base = dirname(__DIR__);
$manifestPath = '';
$maxAge = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--manifest=')) $manifestPath = substr($arg, 11);
    if (str_starts_with($arg, '--max-age-seconds=')) $maxAge = (int) substr($arg, 18);
}
$policy = is_file($base . '/config/production-proof.php') ? require $base . '/config/production-proof.php' : [];
$maxAge ??= (int) ($policy['evidence_max_age_seconds'] ?? 86400);
$failures = [];
if ($manifestPath === '' || !is_file($manifestPath)) {
    $failures[] = 'evidence manifest is required and must exist';
} else {
    $raw = file_get_contents($manifestPath);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest)) {
            $failures[] = 'evidence manifest must be valid JSON';
        } else {
            if (($manifest['certification_version'] ?? null) === 1) {
                $checker = $base . '/scripts/check-certification.php';
                $output = []; $exit = 1;
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($checker) . ' --manifest=' . escapeshellarg($manifestPath) . ' 2>&1', $output, $exit);
                if ($exit !== 0) $failures[] = 'certification manifest contract failed: ' . implode('; ', $output);
            }
            $generated = is_string($manifest['generated_at'] ?? null) ? date_create_immutable($manifest['generated_at']) : false;
        if ($generated === false || $generated->getOffset() !== 0) $failures[] = 'generated_at must be a UTC timestamp';
        else {
            $age = time() - $generated->getTimestamp();
            if ($age < 0 || $age > $maxAge) $failures[] = 'evidence is stale or generated in the future';
        }
        $release = $manifest['release'] ?? null;
        if (!is_array($release)) $failures[] = 'release identity is missing';
        else {
            foreach (['run_id', 'commit', 'actor'] as $key) {
                if (!is_string($release[$key] ?? null) || trim($release[$key]) === '' || $release[$key] === 'unknown') $failures[] = 'release identity requires ' . $key;
            }
        }
    }
}
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'evidence freshness and identity checks passed' . PHP_EOL);
