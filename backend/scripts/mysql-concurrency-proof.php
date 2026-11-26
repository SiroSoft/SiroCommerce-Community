<?php

declare(strict_types=1);

/**
 * MySQL 8 certification harness. This is deliberately database-level: two
 * independent PDO sessions contend on the same rows and unique keys.
 */
require __DIR__ . '/mysql-concurrency-proof-schema.php';

$requiredTables = [
    'inventory_items' => ['id', 'variant_id', 'source_id', 'on_hand', 'reserved'],
    'inventory_sources' => ['source_id', 'state'],
    'reservations' => ['id', 'variant_id', 'source_id', 'qty', 'state', 'idempotency_key', 'allocation_key'],
    'inventory_transfers' => ['id', 'variant_id', 'from_source_id', 'to_source_id', 'qty', 'state', 'idempotency_key'],
    'stock_ledger' => ['id', 'variant_id', 'source_id', 'delta', 'reason', 'ref_type', 'ref_id'],
];
$manifest = [
    'manifest_version' => 1,
    'proof' => 'mysql8-atp-reservation-transfer',
    'status' => 'BLOCK',
    'started_at_utc' => gmdate('c'),
    'driver_requested' => strtolower((string) (getenv('TEST_DB_DRIVER') ?: 'sqlite')),
    'connections' => ['expected' => 2, 'distinct_ids' => [], 'verified' => false],
    'preflight' => ['required_tables' => $requiredTables, 'checks' => []],
    'checks' => [],
    'cleanup' => ['attempted' => false, 'completed' => false],
];

$emit = static function (array $value, int $exit): never {
    $value['finished_at_utc'] = gmdate('c');
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $evidenceFile = getenv('MYSQL_PROOF_EVIDENCE_FILE');
    if (is_string($evidenceFile) && $evidenceFile !== '') file_put_contents($evidenceFile, $json . PHP_EOL, LOCK_EX);
    echo $json . PHP_EOL;
    exit($exit);
};

if ($manifest['driver_requested'] !== 'mysql') {
    $manifest['status'] = 'SKIP';
    $manifest['reason'] = 'TEST_DB_DRIVER=mysql is required; SQLite is not certification evidence';
    $emit($manifest, 0);
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('TEST_DB_HOST') ?: '127.0.0.1', (int) (getenv('TEST_DB_PORT') ?: 3306), getenv('TEST_DB_EFFECTIVE_DATABASE') ?: (getenv('TEST_DB_DATABASE') ?: 'sirocommerce_test'));
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try {
    $a = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', $options);
    $b = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', $options);
} catch (Throwable $e) {
    $manifest['reason'] = 'MySQL connection failed: ' . $e->getMessage();
    $emit($manifest, 2);
}

$manifest['connections']['distinct_ids'] = [(int) $a->query('SELECT CONNECTION_ID()')->fetchColumn(), (int) $b->query('SELECT CONNECTION_ID()')->fetchColumn()];
$manifest['connections']['verified'] = count(array_unique($manifest['connections']['distinct_ids'])) === 2;
$preflight = static function (PDO $db, array $tables): array {
    $checks = [];
    $version = (string) $db->query('SELECT VERSION()')->fetchColumn();
    $checks['mysql8'] = preg_match('/^8\./', $version) === 1;
    foreach ($tables as $table => $columns) {
        $statement = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $statement->execute([$table]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $checks['table:' . $table] = array_diff($columns, $actual) === [];
    }
    $engines = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('inventory_items','reservations','inventory_transfers','stock_ledger')")->fetchAll();
    $checks['innodb'] = count($engines) === 4 && count(array_filter($engines, static fn (array $row): bool => strtoupper((string) $row['ENGINE']) === 'INNODB')) === 4;
    $checks['isolation'] = in_array(strtoupper((string) $db->query('SELECT @@transaction_isolation')->fetchColumn()), ['REPEATABLE-READ', 'READ-COMMITTED'], true);
    return ['version' => $version, 'checks' => $checks];
};
try {
    $preflightA = $preflight($a, $requiredTables);
    $preflightB = $preflight($b, $requiredTables);
} catch (Throwable $e) {
    $manifest['reason'] = 'Schema preflight query failed: ' . $e->getMessage();
    $emit($manifest, 2);
}
$manifest['preflight']['connection_a'] = $preflightA;
$manifest['preflight']['connection_b'] = $preflightB;
$manifest['preflight']['checks'] = $preflightA['checks'];
$manifest['preflight']['checks']['two_sessions_same_isolation'] = $preflightA['checks']['isolation'] && $preflightA['checks']['isolation'] === $preflightB['checks']['isolation'];
$manifest['preflight']['passed'] = mysqlProofAllTrue($preflightA['checks']) && mysqlProofAllTrue($preflightB['checks']) && $manifest['connections']['verified'];
if (!$manifest['preflight']['passed']) {
    $manifest['reason'] = 'MySQL 8/InnoDB/migrated-schema/two-session preflight failed';
    $emit($manifest, 2);
}

$suffix = substr(hash('sha256', random_bytes(16)), 0, 12);
$variant = 'proof-' . $suffix;
$sourceA = 'proof-a-' . $suffix;
$sourceB = 'proof-b-' . $suffix;
$reservationKey = 'proof-reservation-' . $suffix;
$transferKey = 'proof-transfer-' . $suffix;
$cleanup = static function (PDO $db) use ($variant, $sourceA, $sourceB): void {
    $db->prepare('DELETE FROM stock_ledger WHERE variant_id = ?')->execute([$variant]);
    $db->prepare('DELETE FROM reservations WHERE variant_id = ?')->execute([$variant]);
    $db->prepare('DELETE FROM inventory_transfers WHERE variant_id = ?')->execute([$variant]);
    $db->prepare('DELETE FROM inventory_items WHERE variant_id = ?')->execute([$variant]);
    $db->prepare('DELETE FROM inventory_sources WHERE source_id IN (?, ?)')->execute([$sourceA, $sourceB]);
};
$manifest['cleanup']['attempted'] = true;
try {
    $cleanup($a);
    $a->exec("SET SESSION innodb_lock_wait_timeout = 2");
    $b->exec("SET SESSION innodb_lock_wait_timeout = 2");
    $a->prepare('INSERT INTO inventory_sources (id, source_id, state) VALUES (?, ?, ?)')->execute([$sourceA . '-id', $sourceA, 'active']);
    $a->prepare('INSERT INTO inventory_sources (id, source_id, state) VALUES (?, ?, ?)')->execute([$sourceB . '-id', $sourceB, 'active']);
    foreach ([[$sourceA, 1, 'ia'], [$sourceB, 1, 'ib']] as [$source, $stock, $idPrefix]) {
        $a->prepare('INSERT INTO inventory_items (id, variant_id, source_id, on_hand, reserved, low_threshold) VALUES (?, ?, ?, ?, 0, 0)')->execute([$idPrefix . $suffix, $variant, $source, $stock]);
    }

    // ATP race: one unit, two sessions, exactly one guarded reservation wins.
    $a->beginTransaction();
    $statement = $a->prepare('UPDATE inventory_items SET reserved = reserved + 1 WHERE variant_id = ? AND source_id = ? AND on_hand - reserved >= 1');
    $statement->execute([$variant, $sourceA]);
    $firstWon = $statement->rowCount() === 1;
    $b->beginTransaction();
    $secondWon = false;
    try {
        $statement = $b->prepare('UPDATE inventory_items SET reserved = reserved + 1 WHERE variant_id = ? AND source_id = ? AND on_hand - reserved >= 1');
        $statement->execute([$variant, $sourceA]);
        $secondWon = $statement->rowCount() === 1;
    } catch (PDOException $e) {
        $manifest['checks']['atp_lock_wait'] = in_array((string) ($e->errorInfo[1] ?? ''), ['1205', '1213'], true);
        $b->rollBack();
    }
    $a->commit();
    if ($b->inTransaction()) $b->commit();
    $available = (int) $a->query("SELECT on_hand - reserved FROM inventory_items WHERE variant_id = '" . $variant . "' AND source_id = '" . $sourceA . "'")->fetchColumn();
    $manifest['checks']['atp_guarded_reservation'] = $firstWon && !$secondWon && $available === 0;

    // Reservation idempotency race: the same business key has one durable row.
    $a->beginTransaction();
    $insert = 'INSERT INTO reservations (id, variant_id, source_id, cart_id, qty, state, idempotency_key, allocation_key) VALUES (?, ?, ?, ?, 1, ?, ?, ?)';
    $a->prepare($insert)->execute([$variant . '-r', $variant, $sourceA, 'cart-' . $suffix, 'held', $reservationKey, $reservationKey]);
    $b->beginTransaction();
    $duplicate = false;
    try {
        $b->prepare($insert)->execute([$variant . '-r2', $variant, $sourceA, 'cart-' . $suffix, 'held', $reservationKey, $reservationKey]);
    } catch (PDOException $e) {
         $duplicate = in_array((string) ($e->errorInfo[1] ?? ''), ['1062', '1205', '1213'], true);
        $b->rollBack();
    }
    $a->commit();
    $count = (int) $a->query("SELECT COUNT(*) FROM reservations WHERE idempotency_key = '" . $reservationKey . "'")->fetchColumn();
    $manifest['checks']['reservation_idempotency'] = $duplicate && $count === 1;

    // Transfer idempotency race: one atomic source-to-source movement is committed.
    $a->beginTransaction();
    $a->prepare('UPDATE inventory_items SET on_hand = on_hand - 1 WHERE variant_id = ? AND source_id = ? AND on_hand - reserved >= 1')->execute([$variant, $sourceB]);
    $a->prepare('UPDATE inventory_items SET on_hand = on_hand + 1 WHERE variant_id = ? AND source_id = ?')->execute([$variant, $sourceA]);
    $transfer = 'INSERT INTO inventory_transfers (id, variant_id, from_source_id, to_source_id, qty, actor, evidence_json, created_at, state, idempotency_key, dispatched_at, received_at) VALUES (?, ?, ?, ?, 1, ?, ?, NOW(), ?, ?, NOW(), NOW())';
    $transferId = $variant . '-t';
    $a->prepare($transfer)->execute([$transferId, $variant, $sourceB, $sourceA, 'proof', '{}', 'completed', $transferKey]);
    $ledger = 'INSERT INTO stock_ledger (id, variant_id, source_id, delta, reason, ref_type, ref_id, actor, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    $a->prepare($ledger)->execute(['la' . $suffix, $variant, $sourceB, -1, 'transfer', 'inventory_transfer', $transferId, 'proof']);
    $a->prepare($ledger)->execute(['lb' . $suffix, $variant, $sourceA, 1, 'transfer', 'inventory_transfer', $transferId, 'proof']);
    $b->beginTransaction();
    $transferDuplicate = false;
    try {
        $b->prepare($transfer)->execute([$variant . '-t2', $variant, $sourceB, $sourceA, 'proof', '{}', 'completed', $transferKey]);
    } catch (PDOException $e) {
         $transferDuplicate = in_array((string) ($e->errorInfo[1] ?? ''), ['1062', '1205', '1213'], true);
        $b->rollBack();
    }
    $a->commit();
    $transferCount = (int) $a->query("SELECT COUNT(*) FROM inventory_transfers WHERE idempotency_key = '" . $transferKey . "'")->fetchColumn();
    $stocks = $a->query("SELECT source_id, on_hand, reserved FROM inventory_items WHERE variant_id = '" . $variant . "' ORDER BY source_id")->fetchAll();
    $ledgerStatement = $a->prepare('SELECT COUNT(*) AS entries, COALESCE(SUM(delta), 0) AS delta FROM stock_ledger WHERE ref_id = ?');
    $ledgerStatement->execute([$transferId]);
    $ledgerEvidence = $ledgerStatement->fetch();
    $manifest['checks']['transfer_idempotency_and_conservation'] = $transferDuplicate && $transferCount === 1 && count($stocks) === 2 && (int) $stocks[0]['on_hand'] + (int) $stocks[1]['on_hand'] === 2 && (int) $ledgerEvidence['entries'] === 2 && (int) $ledgerEvidence['delta'] === 0;
    $manifest['status'] = mysqlProofAllTrue($manifest['checks']) && mysqlProofEvidenceErrors($manifest) === [] ? 'PASS' : 'FAIL';
    $manifest['cleanup']['completed'] = true;
    $cleanup($a);
    $emit($manifest, $manifest['status'] === 'PASS' ? 0 : 1);
} catch (Throwable $e) {
    foreach ([$a, $b] as $db) if ($db->inTransaction()) $db->rollBack();
    try { $cleanup($a); $manifest['cleanup']['completed'] = true; } catch (Throwable) { }
    $manifest['status'] = 'FAIL';
    $manifest['reason'] = $e->getMessage();
    $emit($manifest, 1);
}
