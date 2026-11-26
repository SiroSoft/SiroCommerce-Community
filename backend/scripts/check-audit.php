<?php

declare(strict_types=1);

/** Verifies that the enterprise audit control plane is present and coherent. */
$audit = dirname(__DIR__, 2) . '/docs/audit';
$required = [
    'README.md',
    '00_AUDIT_CHARTER.md',
    '01_CONTROL_MATRIX.md',
    '02_EVIDENCE_REGISTER.md',
    '03_RISK_REGISTER.md',
    '04_RELEASE_AUDIT_CHECKLIST.md',
    '05_FAILURE_DRILL_RECORD.md',
    '06_EXCEPTION_REGISTER.md',
    '07_CHANGE_RECORD.md',
    '08_SIGNOFF.md',
    '09_AUDIT_RUNBOOK.md',
    '10_COMPLIANCE_MAPPING.md',
];
$missing = array_values(array_filter($required, static fn (string $file): bool => !is_file($audit . '/' . $file)));
if ($missing !== []) {
    fwrite(STDERR, "audit: missing " . implode(', ', $missing) . "\n");
    exit(1);
}

$matrix = (string) file_get_contents($audit . '/01_CONTROL_MATRIX.md');
preg_match_all('/\|\s*([A-Z]+-\d{3})\s*\|/', $matrix, $matches);
$ids = $matches[1] ?? [];
$duplicates = array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1));
if ($duplicates !== []) {
    fwrite(STDERR, "audit: duplicate control IDs " . implode(', ', $duplicates) . "\n");
    exit(1);
}
if (count($ids) < 20) {
    fwrite(STDERR, "audit: control matrix is unexpectedly small\n");
    exit(1);
}

echo 'audit: PASS (' . count($required) . ' files, ' . count($ids) . " controls)\n";
