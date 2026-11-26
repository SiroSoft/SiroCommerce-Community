<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\InventoryItem;
use App\Models\Reservation;
use App\Models\StockLedger;
use App\Models\InventoryAdjustment;
use App\Models\InventoryTransfer;
use App\Repositories\Concerns\CreatesModels;
use Siro\Core\Database;
use App\Support\Ids;
use App\Support\DatabaseRetry;

/**
 * Stateless data-access (backend AGENTS §1). Raw SQL only for the guarded
 * atomic stock updates — bound params, driver-agnostic (AGENTS §1.3).
 */
final class InventoryRepository
{
    use CreatesModels;

    /**
     * Execute with PDO-native type binding. Plain execute() binds everything
     * as string, which breaks INT comparisons on SQLite (10 >= '3' is false
     * because INTEGER < TEXT in type ordering). Always PARAM_INT for ints.
     *
     * @param array<int, string|int> $params
     */
    private static function execTyped(string $sql, array $params): int
    {
        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return array<string, mixed>|null */
    public function findItem(string $variantId, string $sourceId = 'default'): ?array
    {
        $row = InventoryItem::query()->where('variant_id', $variantId)->where('source_id', $sourceId)->first();
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    /** @return array<string, mixed> */
    public function ensureItem(string $variantId, string $sourceId = 'default'): array
    {
        $item = $this->findItem($variantId, $sourceId);
        if ($item !== null) {
            return $item;
        }
        return self::createRow(InventoryItem::class, [
            'id' => bin2hex(random_bytes(13)),
            'variant_id' => $variantId,
            'source_id' => $sourceId,
            'on_hand' => 0,
            'reserved' => 0,
            'low_threshold' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    public function setOnHand(string $variantId, int $onHand, string $actor, string $sourceId = 'default', string $idempotencyKey = ''): array
    {
        return $this->adjustOnHand($variantId, $onHand, $actor, $sourceId, 'admin-adjustment', $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function adjustOnHand(string $variantId, int $onHand, string $actor, string $sourceId, string $evidence, string $idempotencyKey = ''): array
    {
        if ($onHand < 0) throw new \InvalidArgumentException('on_hand cannot be negative');
        /** @var array<string, mixed> $result */
        $result = DatabaseRetry::transaction(function () use ($variantId, $onHand, $actor, $sourceId, $evidence, $idempotencyKey): array {
            if ($idempotencyKey !== '') {
                $existing = InventoryAdjustment::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    $existingData = $existing->toArray();
                    if ((string) ($existingData['variant_id'] ?? '') !== $variantId || (int) ($existingData['new_on_hand'] ?? -1) !== $onHand || (string) ($existingData['source_id'] ?? '') !== $sourceId) {
                        throw new \RuntimeException('Idempotency key conflicts with the adjustment request');
                    }
                    $existingData['evidence'] = json_decode((string) ($existingData['evidence_json'] ?? '{}'), true) ?: [];
                    return $existingData;
                }
            }
            // Create and lock the row before reading it. The adjustment and both
            // evidence records must commit or roll back as one unit.
            $this->ensureItem($variantId, $sourceId);
            /** @var \Siro\Core\Model|null $item */
            $item = InventoryItem::query()->where('variant_id', $variantId)->where('source_id', $sourceId)->lockForUpdate()->first();
            if ($item === null) throw new \RuntimeException('Inventory item could not be locked');
            $data = $item->toArray();
            $old = is_numeric($data['on_hand'] ?? null) ? (int) $data['on_hand'] : 0;
            $reserved = is_numeric($data['reserved'] ?? null) ? (int) $data['reserved'] : 0;
            if ($onHand < $reserved) throw new \RuntimeException('on_hand cannot be below reserved quantity');
            self::execTyped('UPDATE inventory_items SET on_hand = ? WHERE variant_id = ? AND source_id = ?', [$onHand, $variantId, $sourceId]);
            $delta = $onHand - $old;
            $id = Ids::new();
            $evidenceData = ['reason' => $evidence, 'old_on_hand' => $old, 'new_on_hand' => $onHand, 'request_id' => $idempotencyKey !== '' ? $idempotencyKey : null];
            self::createRow(InventoryAdjustment::class, ['id' => $id, 'variant_id' => $variantId, 'source_id' => $sourceId, 'old_on_hand' => $old, 'new_on_hand' => $onHand, 'delta' => $delta, 'actor' => $actor, 'evidence_json' => json_encode($evidenceData, JSON_THROW_ON_ERROR), 'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null, 'created_at' => date('Y-m-d H:i:s')]);
            self::createRow(StockLedger::class, ['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'delta' => $delta, 'reason' => 'adjust', 'ref_type' => 'inventory_adjustment', 'ref_id' => $id, 'actor' => $actor, 'created_at' => date('Y-m-d H:i:s')]);
            return ['variant_id' => $variantId, 'source_id' => $sourceId, 'old_on_hand' => $old, 'on_hand' => $onHand, 'delta' => $delta, 'evidence' => $evidenceData, 'adjustment_id' => $id];
        });
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function sourceAvailability(string $variantId, ?string $storeId = null, ?string $companyId = null): array
    {
        $rows = Database::connection()->prepare('SELECT i.source_id, (i.on_hand - i.reserved) AS available, COALESCE(p.priority, s.priority, 0) AS priority, COALESCE(s.cost_minor, 0) AS cost_minor, COALESCE(s.distance_km, 0) AS distance_km, COALESCE(s.lead_time_hours, 0) AS lead_time_hours, COALESCE(s.state, \'active\') AS source_state FROM inventory_items i LEFT JOIN inventory_source_priorities p ON p.source_id = i.source_id AND p.variant_id = i.variant_id LEFT JOIN inventory_sources s ON s.source_id = i.source_id WHERE i.variant_id = ? AND COALESCE(s.state, \'active\') = \'active\' AND (s.store_id IS NULL OR s.store_id = ?) AND (s.company_id IS NULL OR s.company_id = ?) ORDER BY priority ASC, cost_minor ASC, distance_km ASC, lead_time_hours ASC, i.source_id ASC');
        $rows->execute([$variantId, $storeId, $companyId]);
        $result = []; foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) if (is_array($row)) { $clean=[]; foreach ($row as $key=>$value) if (is_string($key)) $clean[$key]=$value; $result[]=$clean; } return $result;
    }

    /**
     * Lock all candidate source rows before reading ATP. Every caller uses the
     * same source-id order, preventing cross-source allocations from deadlocking.
     * The caller owns the surrounding transaction.
     *
     * @param array<int, string> $sourceIds
     */
    public function lockSources(string $variantId, array $sourceIds): void
    {
        $ordered = array_values(array_unique(array_filter($sourceIds, static fn (string $id): bool => $id !== '')));
        sort($ordered, SORT_STRING);
        $sql = 'SELECT id FROM inventory_items WHERE variant_id = ? AND source_id = ?';
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (is_string($driver) && strtolower($driver) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        foreach ($ordered as $sourceId) {
            Database::connection()->prepare($sql)->execute([$variantId, $sourceId]);
        }
    }

    /** @return array<string, mixed> */
    public function setSourcePriority(string $variantId, string $sourceId, int $priority): array
    {
        if ($sourceId === '' || $priority < 0) throw new \InvalidArgumentException('Invalid source priority');
        $id = Ids::new();
        $sql = 'INSERT INTO inventory_source_priorities (id, variant_id, source_id, priority, state) VALUES (?, ?, ?, ?, ?) ON CONFLICT(variant_id, source_id) DO UPDATE SET priority = excluded.priority, state = excluded.state';
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (is_string($driver) && strtolower($driver) === 'mysql') $sql = 'INSERT INTO inventory_source_priorities (id, variant_id, source_id, priority, state) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE priority = VALUES(priority), state = VALUES(state)';
        $statement = Database::connection()->prepare($sql); $statement->execute([$id, $variantId, $sourceId, $priority, 'active']);
        return ['variant_id' => $variantId, 'source_id' => $sourceId, 'priority' => $priority, 'state' => 'active'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function transfer(array $data): array
    {
        $from = is_string($data['from_source_id'] ?? null) ? $data['from_source_id'] : ''; $to = is_string($data['to_source_id'] ?? null) ? $data['to_source_id'] : ''; $variant = is_string($data['variant_id'] ?? null) ? $data['variant_id'] : ''; $qty = is_int($data['qty'] ?? null) ? $data['qty'] : (is_numeric($data['qty'] ?? null) ? (int)$data['qty'] : 0);
        if ($from === '' || $to === '' || $from === $to || $variant === '' || $qty <= 0) throw new \InvalidArgumentException('Invalid inventory transfer');
        $requestKey = is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : '';
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $requestKey)) throw new \InvalidArgumentException('Invalid idempotency key');
        try {
            $result = \App\Support\DatabaseRetry::transaction(function () use ($data, $from, $to, $variant, $qty, $requestKey): array {
            $key = $requestKey;
            $fingerprint = hash('sha256', json_encode([$variant, $from, $to, $qty], JSON_THROW_ON_ERROR));
            $existing = InventoryTransfer::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $existingData = $existing->toArray();
                $evidenceValue = $existingData['evidence_json'] ?? null;
                $evidence = is_string($evidenceValue) ? json_decode($evidenceValue, true) : null;
                if (is_array($evidence) && isset($evidence['request_fingerprint']) && $evidence['request_fingerprint'] !== $fingerprint) throw new \RuntimeException('Idempotency key conflicts with the transfer request');
                return $existingData;
            }
            $this->ensureItem($variant, $from); $this->ensureItem($variant, $to);
            $ordered = [$from, $to]; sort($ordered, SORT_STRING);
            $lockSql = 'SELECT id FROM inventory_items WHERE variant_id = ? AND source_id = ?';
            $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if (is_string($driver) && strtolower($driver) === 'mysql') $lockSql .= ' FOR UPDATE';
            foreach ($ordered as $source) Database::connection()->prepare($lockSql)->execute([$variant, $source]);
            if (self::execTyped('UPDATE inventory_items SET on_hand = on_hand - ? WHERE variant_id = ? AND source_id = ? AND (on_hand - reserved) >= ?', [$qty, $variant, $from, $qty]) !== 1) throw new \RuntimeException('Insufficient transferable stock');
            self::execTyped('UPDATE inventory_items SET on_hand = on_hand + ? WHERE variant_id = ? AND source_id = ?', [$qty, $variant, $to]);
            $id = is_string($data['id'] ?? null) ? $data['id'] : Ids::new(); $actor = is_string($data['actor'] ?? null) ? $data['actor'] : 'system';
            $evidence = ['reason' => is_string($data['reason'] ?? null) ? $data['reason'] : '', 'lock_order' => $ordered, 'request_fingerprint' => $fingerprint];
            self::createRow(InventoryTransfer::class, ['id' => $id, 'variant_id' => $variant, 'from_source_id' => $from, 'to_source_id' => $to, 'qty' => $qty, 'actor' => $actor, 'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s'), 'state' => 'completed', 'idempotency_key' => $key, 'dispatched_at' => date('Y-m-d H:i:s'), 'received_at' => date('Y-m-d H:i:s')]);
            foreach ([[$from, -$qty], [$to, $qty]] as [$source, $delta]) self::createRow(StockLedger::class, ['id' => Ids::new(), 'variant_id' => $variant, 'source_id' => $source, 'delta' => $delta, 'reason' => 'transfer', 'ref_type' => 'inventory_transfer', 'ref_id' => $id, 'actor' => $actor, 'created_at' => date('Y-m-d H:i:s')]);
            return ['id' => $id, 'variant_id' => $variant, 'from_source_id' => $from, 'to_source_id' => $to, 'qty' => $qty, 'actor' => $actor, 'state' => 'completed', 'idempotency_key' => $key, 'evidence' => $evidence];
            });
        } catch (\PDOException $e) {
            $existing = InventoryTransfer::query()->where('idempotency_key', $requestKey)->first();
            if ($existing === null) throw $e;
            $row = $existing->toArray();
            $evidenceValue = $row['evidence_json'] ?? null;
            $evidence = is_string($evidenceValue) ? json_decode($evidenceValue, true) : null;
            $fingerprint = hash('sha256', json_encode([$variant, $from, $to, $qty], JSON_THROW_ON_ERROR));
            if (is_array($evidence) && isset($evidence['request_fingerprint']) && $evidence['request_fingerprint'] !== $fingerprint) throw new \RuntimeException('Idempotency key conflicts with the transfer request');
            $result = $row;
        }
        if (!is_array($result)) throw new \RuntimeException('Inventory transfer returned invalid evidence');
        $clean = [];
        foreach ($result as $key => $value) if (is_string($key)) $clean[$key] = $value;
        return $clean;
    }

    /**
     * Guarded atomic reserve: only succeeds when available stock covers qty.
     * Returns affected rows (1 = reserved, 0 = insufficient).
     */
    public function tryReserve(string $variantId, int $qty, string $sourceId = 'default'): int
    {
        $this->ensureItem($variantId, $sourceId);
        return self::execTyped(
            'UPDATE inventory_items SET reserved = reserved + ? WHERE variant_id = ? AND source_id = ? AND (on_hand - reserved) >= ?',
            [$qty, $variantId, $sourceId, $qty]
        );
    }

    public function releaseReserved(string $variantId, int $qty, string $sourceId = 'default'): void
    {
        if (self::execTyped(
            'UPDATE inventory_items SET reserved = reserved - ? WHERE variant_id = ? AND source_id = ? AND reserved >= ?',
            [$qty, $variantId, $sourceId, $qty]
        ) !== 1) throw new \RuntimeException('Reservation ledger invariant failed');
    }

    public function commitReserved(string $variantId, int $qty, string $sourceId = 'default'): void
    {
        if (self::execTyped(
            'UPDATE inventory_items SET reserved = reserved - ?, on_hand = on_hand - ? WHERE variant_id = ? AND source_id = ? AND reserved >= ? AND on_hand >= ?',
            [$qty, $qty, $variantId, $sourceId, $qty, $qty]
        ) !== 1) throw new \RuntimeException('Reservation commit invariant failed');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createReservation(array $data): array
    {
        return self::createRow(Reservation::class, $data);
    }

    /** @return array<string, mixed>|null */
    public function findReservation(string $id): ?array
    {
        $row = Reservation::find($id);
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function findReservationByIdempotency(string $key): ?array
    {
        $row = Reservation::query()->where('idempotency_key', $key)->first();
        return $row === null ? null : $row->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function findReservationsByAllocationKey(string $key): array
    {
        return array_map(static fn ($row): array => $row->toArray(), Reservation::query()->where('allocation_key', $key)->orderBy('id')->get());
    }

    public function markReservation(string $id, string $state, ?string $orderId = null): int
    {
        $row = Reservation::find($id);
        if ($row === null) {
            return 0;
        }
        $attrs = ['state' => $state];
        if ($orderId !== null) {
            $attrs['order_id'] = $orderId;
        }
        $query = Reservation::query()->where('id', $id)->where('state', 'held');
        if ($state === 'released') $attrs['released_at'] = date('Y-m-d H:i:s');
        return $query->update($attrs) ? 1 : 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function heldForCart(string $cartId): array
    {
        $rows = Reservation::query()
            ->where('cart_id', $cartId)
            ->where('state', 'held')
            ->get();
        return array_map(static function ($m): array {
            /** @var array<string, mixed> $out */
            $out = $m->toArray();
            return $out;
        }, $rows);
    }

    /** @param array<string, mixed> $data */
    public function appendLedger(array $data): void
    {
        self::createRow(StockLedger::class, $data);
    }

    public function receiveReturn(string $variantId, int $qty, string $disposition, string $returnId, string $lineId, string $actor, string $sourceId = 'default'): void
    {
        if ($qty <= 0 || !in_array($disposition, ['restock', 'damaged'], true)) throw new \InvalidArgumentException('Invalid return inventory posting');
        DatabaseRetry::transaction(function () use ($variantId, $qty, $disposition, $lineId, $actor, $sourceId): void {
            $this->ensureItem($variantId, $sourceId);
            $item = InventoryItem::query()->where('variant_id', $variantId)->where('source_id', $sourceId)->lockForUpdate()->first();
            if ($item === null) throw new \RuntimeException('Inventory item could not be locked');
            // Completion records the posting after this call. The ledger is the
            // idempotency fence if that second write is interrupted or retried.
            $existing = StockLedger::query()->where('ref_type', 'return_line')->where('ref_id', $lineId)->first();
            if ($existing !== null) {
                $evidence = $existing->toArray();
                $existingDelta = is_numeric($evidence['delta'] ?? null) ? (int) $evidence['delta'] : null;
                if (($evidence['variant_id'] ?? null) !== $variantId || $existingDelta !== ($disposition === 'restock' ? $qty : 0)) {
                    throw new \RuntimeException('Return inventory posting conflicts with existing evidence');
                }
                return;
            }
            $delta = $disposition === 'restock' ? $qty : 0;
            if ($delta !== 0) self::execTyped('UPDATE inventory_items SET on_hand = on_hand + ? WHERE variant_id = ? AND source_id = ?', [$delta, $variantId, $sourceId]);
            self::createRow(StockLedger::class, ['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'delta' => $delta, 'reason' => 'rma_' . $disposition, 'ref_type' => 'return_line', 'ref_id' => $lineId, 'actor' => $actor, 'created_at' => date('Y-m-d H:i:s')]);
        });
    }
}
