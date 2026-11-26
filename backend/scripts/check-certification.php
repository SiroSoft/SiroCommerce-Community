<?php

declare(strict_types=1);

/** Validate the auditable production certification contract without contacting production. */
$manifestPath = '';
$maxAgeOverride = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--manifest=')) $manifestPath = substr($arg, 11);
    if (str_starts_with($arg, '--max-age-seconds=')) $maxAgeOverride = (int) substr($arg, 18);
}
$base = dirname(__DIR__);
$policy = is_file($base . '/config/production-proof.php') ? require $base . '/config/production-proof.php' : [];
$maxAge = $maxAgeOverride ?? (int) ($policy['evidence_max_age_seconds'] ?? 86400);
$failures = [];
$required = [
    'production-proof', 'provider-preflight', 'provider-live-smoke', 'backup-restore',
    'backup-infrastructure', 'reconciliation', 'config-static',
];
$allowed = ['PASS', 'FAIL', 'BLOCKED_EXTERNAL', 'NOT_RUN'];
$sensitiveKey = '/(secret|token|password|api[_-]?key|authorization|private[_-]?key)/i';
$sensitiveValue = '/(Bearer\s+|sk_live_|-----BEGIN .* PRIVATE KEY-----)/i';

$scan = static function (mixed $value, string $path = '') use (&$scan, &$failures, $sensitiveKey, $sensitiveValue): void {
    if (is_array($value)) {
        foreach ($value as $key => $nested) {
            $keyPath = $path . '/' . (string) $key;
            if ((string) $key !== 'secrets_included' && preg_match($sensitiveKey, (string) $key) === 1 && $nested !== '[redacted]') $failures[] = 'sensitive field must be [redacted]: ' . $keyPath;
            $scan($nested, $keyPath);
        }
    } elseif (is_string($value) && preg_match($sensitiveValue, $value) === 1) {
        $failures[] = 'sensitive value found in manifest: ' . $path;
    }
};

if ($manifestPath === '' || !is_file($manifestPath)) {
    $failures[] = 'certification manifest is required and must exist';
} else {
    $raw = file_get_contents($manifestPath);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest)) {
        $failures[] = 'certification manifest must be valid JSON';
    } else {
        if (($manifest['certification_version'] ?? null) !== 1) $failures[] = 'certification_version must be 1';
        if (!in_array($manifest['status'] ?? null, $allowed, true)) $failures[] = 'manifest status is invalid';
        foreach (['generated_at', 'freshness.as_of'] as $field) {
            $parts = explode('.', $field); $value = $manifest;
            foreach ($parts as $part) $value = is_array($value) ? ($value[$part] ?? null) : null;
            $date = is_string($value) ? date_create_immutable($value) : false;
            if ($date === false || $date->getOffset() !== 0) $failures[] = $field . ' must be a UTC timestamp';
        }
        $freshness = $manifest['freshness'] ?? [];
        $effectiveMaxAge = is_int($freshness['max_age_seconds'] ?? null) ? $freshness['max_age_seconds'] : $maxAge;
        if ($effectiveMaxAge <= 0) $failures[] = 'freshness.max_age_seconds must be positive';
        $generated = date_create_immutable((string) ($manifest['generated_at'] ?? ''));
        if ($generated !== false) { $age = time() - $generated->getTimestamp(); if ($age < 0 || $age > $effectiveMaxAge) $failures[] = 'certification manifest is stale or generated in the future'; }
        $release = $manifest['release'] ?? null;
        foreach (['run_id', 'commit', 'actor'] as $key) if (!is_array($release) || !is_string($release[$key] ?? null) || trim($release[$key]) === '' || $release[$key] === 'unknown') $failures[] = 'release identity requires ' . $key;
        $redaction = $manifest['redaction'] ?? null;
        if (!is_array($redaction) || ($redaction['applied'] ?? false) !== true || ($redaction['secrets_included'] ?? true) !== false || !is_string($redaction['policy'] ?? null) || trim($redaction['policy']) === '') $failures[] = 'redaction declaration is incomplete';
        $seen = [];
        foreach ($manifest['checklist'] ?? [] as $item) {
            if (!is_array($item)) { $failures[] = 'checklist item must be an object'; continue; }
            $id = (string) ($item['id'] ?? ''); $status = $item['status'] ?? null; $seen[] = $id;
            if (!in_array($status, $allowed, true)) $failures[] = 'invalid checklist status: ' . $id;
            if (!is_string($item['requirement'] ?? null) || trim($item['requirement']) === '') $failures[] = 'requirement missing: ' . $id;
            if (!is_string($item['owner'] ?? null) || trim($item['owner']) === '') $failures[] = 'owner missing: ' . $id;
            if (!is_array($item['evidence'] ?? null)) $failures[] = 'evidence list missing: ' . $id;
            if (in_array($status, ['BLOCKED_EXTERNAL', 'NOT_RUN'], true) && (!is_string($item['reason'] ?? null) || trim($item['reason']) === '')) $failures[] = 'blocked/not-run item requires reason: ' . $id;
            if ($status === 'PASS' && (!is_array($item['evidence'] ?? null) || $item['evidence'] === [])) $failures[] = 'passing item requires evidence: ' . $id;
        }
        foreach ($required as $id) if (!in_array($id, $seen, true)) $failures[] = 'required checklist item missing: ' . $id;
        $itemStatuses = [];
        foreach ($manifest['checklist'] ?? [] as $item) if (is_array($item) && is_string($item['status'] ?? null)) $itemStatuses[] = $item['status'];
        $derivedStatus = in_array('FAIL', $itemStatuses, true) ? 'FAIL' : (in_array('BLOCKED_EXTERNAL', $itemStatuses, true) ? 'BLOCKED_EXTERNAL' : (in_array('NOT_RUN', $itemStatuses, true) ? 'NOT_RUN' : 'PASS'));
        if (($manifest['status'] ?? null) !== $derivedStatus) $failures[] = 'manifest status does not match checklist status: expected ' . $derivedStatus;
        $scan($manifest);
    }
}
if ($failures !== []) { fwrite(STDERR, implode(PHP_EOL, array_unique($failures)) . PHP_EOL); exit(1); }
fwrite(STDOUT, "production certification contract checks passed\n");
