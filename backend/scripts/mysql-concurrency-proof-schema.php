<?php

declare(strict_types=1);

/** @param array<string, bool> $checks */
function mysqlProofAllTrue(array $checks): bool
{
    return $checks !== [] && !in_array(false, $checks, true);
}

/** @return array<int, string> */
function mysqlProofEvidenceErrors(array $evidence): array
{
    $errors = [];
    if (($evidence['manifest_version'] ?? null) !== 1) $errors[] = 'manifest_version must be 1';
    if (($evidence['proof'] ?? null) !== 'mysql8-atp-reservation-transfer') $errors[] = 'proof name is invalid';
    if (!in_array($evidence['status'] ?? null, ['SKIP', 'BLOCK', 'FAIL', 'PASS'], true)) $errors[] = 'status is invalid';
    if (!isset($evidence['connections']['expected'], $evidence['connections']['verified'])) $errors[] = 'connection evidence is required';
    if (($evidence['status'] ?? null) === 'PASS' && (($evidence['connections']['expected'] ?? null) !== 2 || ($evidence['connections']['verified'] ?? false) !== true || !isset($evidence['preflight']['passed']) || $evidence['preflight']['passed'] !== true || ($evidence['checks']['atp_lock_wait'] ?? false) !== true || !isset($evidence['checks']['atp_guarded_reservation'], $evidence['checks']['reservation_idempotency'], $evidence['checks']['transfer_idempotency_and_conservation']))) $errors[] = 'PASS requires two verified connections, observed lock contention, preflight, and all inventory checks';
    return $errors;
}
