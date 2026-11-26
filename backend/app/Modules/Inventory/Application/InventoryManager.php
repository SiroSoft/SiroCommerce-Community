<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Contracts\InventoryReader;
use App\Modules\Inventory\Contracts\InventoryStore;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use App\Support\Arr;
use App\Support\Ids;
use App\Support\DatabaseRetry;

final class InventoryManager implements InventoryReader
{
    public function __construct(private readonly InventoryStore $store) {}

    /** @return array<string, mixed> */
    /** @param array<string, string>|null $scope */
    public function reserve(string $variantId, int $qty, string $cartId, int $ttlSeconds = 900, string $sourceId = 'default', ?string $idempotencyKey = null, ?array $scope = null): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('qty must be > 0');
        if ($ttlSeconds <= 0) throw new \InvalidArgumentException('ttl_seconds must be > 0');
        // Allocation-policy modules may veto or redirect the reservation.
        Hooks::fire(HookName::InventoryBeforeReserve, ['variant_id' => $variantId, 'qty' => $qty, 'cart_id' => $cartId, 'source_id' => $sourceId]);
        $result = DatabaseRetry::transaction(function () use ($variantId, $qty, $cartId, $ttlSeconds, $sourceId, $idempotencyKey, $scope): array {
            if ($idempotencyKey !== null) {
                $existing = $this->store->reservationByIdempotency($idempotencyKey);
                if ($existing !== null) {
                    if (Arr::str($existing, 'variant_id') !== $variantId || Arr::int($existing, 'qty') !== $qty || Arr::str($existing, 'cart_id') !== $cartId) throw new \RuntimeException('Idempotency key conflicts with the reservation request');
                    return $existing;
                }
            }
            if ($this->store->tryReserve($variantId, $qty, $sourceId) !== 1) throw new \RuntimeException('Out of stock');
            $reservation = $this->store->createReservation(['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'cart_id' => $cartId, 'order_id' => null, 'qty' => $qty, 'state' => 'held', 'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds), 'idempotency_key' => $idempotencyKey, 'store_id' => $scope['store_id'] ?? null, 'channel_id' => $scope['channel_id'] ?? null, 'locale' => $scope['locale'] ?? null, 'currency' => $scope['currency'] ?? null]);
            $this->store->ledger(['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'delta' => -$qty, 'reason' => 'reserve', 'ref_type' => 'reservation', 'ref_id' => Arr::requiredStr($reservation, 'id'), 'actor' => 'checkout', 'created_at' => date('Y-m-d H:i:s')]);
            return $reservation;
        });
        if (!is_array($result)) throw new \RuntimeException('Inventory reservation returned an invalid result');
        $reservation = [];
        foreach ($result as $key => $value) if (is_string($key)) $reservation[$key] = $value;
        return $reservation;
    }

    /**
     * @param array<int, array{variant_id: string, qty: int, source_id?: string}> $components
     * @return array<int, array<string, mixed>>
     */
    public function reserveBundle(array $components, string $cartId, int $ttlSeconds = 900): array
    {
        if ($components === []) throw new \InvalidArgumentException('Bundle components are required');
        $reservations = [];
        try {
            foreach ($components as $component) {
                $reservation = $this->reserve($component['variant_id'], $component['qty'], $cartId, $ttlSeconds, $component['source_id'] ?? 'default');
                $reservations[] = $reservation;
            }
            return $reservations;
        } catch (\Throwable $e) {
            foreach ($reservations as $reservation) {
                $id = $reservation['id'] ?? null;
                if (is_string($id)) $this->release($id, 'bundle_reserve_rollback');
            }
            throw $e;
        }
    }

    /**
     * Reserve one line from the highest-priority sources without overselling.
     *
     * @param array<string, mixed> $policy
     * @return array<int, array<string, mixed>>
     */
    public function reserveAcrossSources(string $variantId, int $qty, string $cartId, int $ttlSeconds = 900, array $policy = [], ?string $idempotencyKey = null, ?string $storeId = null, ?string $companyId = null): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('qty must be > 0');
        if ($ttlSeconds <= 0) throw new \InvalidArgumentException('ttl_seconds must be > 0');
        if ($idempotencyKey !== null && !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Invalid idempotency key');
        }
        $requestedPolicy = Arr::str($policy, 'backorder_policy', 'fail');
        $policyName = in_array($requestedPolicy, ['fail', 'backorder', 'preorder'], true) ? $requestedPolicy : 'fail';
        if ($policyName === 'backorder' && ($policy['allow_backorder'] ?? true) === false) throw new \InvalidArgumentException('Backorders are not allowed by source policy');
        if ($policyName === 'preorder' && ($policy['allow_preorder'] ?? true) === false) throw new \InvalidArgumentException('Preorders are not allowed by source policy');
        $allowSplit = ($policy['allow_split'] ?? true) !== false;
        $allowedSources = $this->sourcePolicyIds($policy, 'allowed_source_ids');
        $excludedSources = $this->sourcePolicyIds($policy, 'excluded_source_ids');
        $allocationKey = $idempotencyKey ?? Ids::new();
        $requestFingerprint = hash('sha256', json_encode([$variantId, $qty, $cartId, $ttlSeconds, $policyName, $allowSplit, $allowedSources, $excludedSources, $storeId, $companyId], JSON_THROW_ON_ERROR));
        try {
            $result = DatabaseRetry::transaction(function () use ($variantId, $qty, $cartId, $ttlSeconds, $policyName, $allowSplit, $idempotencyKey, $storeId, $companyId, $allowedSources, $excludedSources, $allocationKey, $requestFingerprint): array {
            if ($idempotencyKey !== null) {
                $existing = $this->store->reservationByIdempotency($idempotencyKey);
                if ($existing !== null) {
                    $evidence = Arr::row(json_decode(Arr::str($existing, 'evidence_json', '{}'), true));
                    if (Arr::str($evidence, 'request_fingerprint') !== '' && Arr::str($evidence, 'request_fingerprint') !== $requestFingerprint) throw new \RuntimeException('Idempotency key conflicts with the allocation request');
                    $group = $this->store->reservationsByAllocationKey(Arr::str($existing, 'allocation_key', $idempotencyKey));
                    return $group === [] ? [$existing] : $group;
                }
            }
            $remaining = $qty; $reservations = [];
            $sources = $this->store->sourceAvailability($variantId, $storeId, $companyId);
             $sources = array_values(array_filter($sources, static function (array $source) use ($allowedSources, $excludedSources): bool {
                 $sourceId = Arr::str($source, 'source_id', 'default');
                 return ($allowedSources === [] || in_array($sourceId, $allowedSources, true)) && !in_array($sourceId, $excludedSources, true);
             }));
             // Lock candidates by source ID, not allocation priority, then re-read ATP.
             // This makes competing multi-source allocations use one MySQL lock order.
             $this->store->lockSources($variantId, array_map(static fn (array $source): string => Arr::str($source, 'source_id', 'default'), $sources));
             $sources = array_values(array_filter($this->store->sourceAvailability($variantId, $storeId, $companyId), static function (array $source) use ($allowedSources, $excludedSources): bool {
                 $sourceId = Arr::str($source, 'source_id', 'default');
                 return ($allowedSources === [] || in_array($sourceId, $allowedSources, true)) && !in_array($sourceId, $excludedSources, true);
             }));
             usort($sources, static fn (array $a, array $b): int => [Arr::int($a, 'priority'), Arr::int($a, 'cost_minor'), Arr::int($a, 'distance_km'), Arr::int($a, 'lead_time_hours'), Arr::str($a, 'source_id')] <=> [Arr::int($b, 'priority'), Arr::int($b, 'cost_minor'), Arr::int($b, 'distance_km'), Arr::int($b, 'lead_time_hours'), Arr::str($b, 'source_id')]);
            foreach ($sources as $source) {
                if ($remaining <= 0) break;
                $sourceId = Arr::str($source, 'source_id', 'default');
                $take = min($remaining, max(0, Arr::int($source, 'available')));
                if ($take > 0) {
                    if (!$allowSplit && $take < $qty) continue;
                    $evidence = ['policy' => $policyName, 'priority' => Arr::int($source, 'priority'), 'cost_minor' => Arr::int($source, 'cost_minor'), 'distance_km' => Arr::int($source, 'distance_km'), 'lead_time_hours' => Arr::int($source, 'lead_time_hours'), 'source_id' => $sourceId];
                    $evidence['request_fingerprint'] = $requestFingerprint;
                    $reservations[] = $this->reserveInTransaction($variantId, $take, $cartId, $ttlSeconds, $sourceId, $reservations === [] ? $idempotencyKey : null, $storeId, $companyId, $evidence, $allocationKey);
                    $remaining -= $take;
                }
            }
            if ($remaining > 0 && $policyName === 'fail') throw new \RuntimeException('Out of stock');
            if ($remaining > 0) {
                $evidence = ['policy' => $policyName, 'unallocated_qty' => $remaining, 'sources' => $sources, 'request_fingerprint' => $requestFingerprint];
                $reservations[] = $this->store->createReservation(['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => 'unallocated', 'cart_id' => $cartId, 'order_id' => null, 'qty' => $remaining, 'state' => $policyName, 'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds), 'idempotency_key' => $reservations === [] ? $idempotencyKey : null, 'allocation_key' => $allocationKey, 'store_id' => $storeId, 'company_id' => $companyId, 'policy' => $policyName, 'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
            }
            return $reservations;
            });
        } catch (\PDOException $e) {
            if ($idempotencyKey === null) throw $e;
            $existing = $this->store->reservationByIdempotency($idempotencyKey);
            if ($existing === null) throw $e;
            $result = $this->store->reservationsByAllocationKey(Arr::str($existing, 'allocation_key', $idempotencyKey));
            if ($result === []) $result = [$existing];
        }
        if (!is_array($result)) throw new \RuntimeException('Inventory allocation returned an invalid result');
        $reservations = [];
            foreach ($result as $reservation) {
            if (!is_array($reservation)) throw new \RuntimeException('Inventory allocation returned an invalid reservation');
            $clean = [];
            foreach ($reservation as $key => $value) if (is_string($key)) $clean[$key] = $value;
            $reservations[] = $clean;
            }
        usort($reservations, static function (array $a, array $b): int {
            $aBackorder = Arr::str($a, 'state') === 'backorder' ? 1 : 0;
            $bBackorder = Arr::str($b, 'state') === 'backorder' ? 1 : 0;
            if ($aBackorder !== $bBackorder) return $aBackorder <=> $bBackorder;
            $aEvidence = Arr::row(json_decode(Arr::str($a, 'evidence_json', '{}'), true));
            $bEvidence = Arr::row(json_decode(Arr::str($b, 'evidence_json', '{}'), true));
            $priority = Arr::int($aEvidence, 'priority') <=> Arr::int($bEvidence, 'priority');
            return $priority !== 0 ? $priority : Arr::str($a, 'source_id') <=> Arr::str($b, 'source_id');
        });
        return $reservations;
    }

    /**
     * The caller owns the transaction; this prevents partial cross-source holds.
     *
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function reserveInTransaction(string $variantId, int $qty, string $cartId, int $ttlSeconds, string $sourceId, ?string $idempotencyKey = null, ?string $storeId = null, ?string $companyId = null, array $evidence = [], ?string $allocationKey = null): array
    {
        if ($this->store->tryReserve($variantId, $qty, $sourceId) !== 1) throw new \RuntimeException('Out of stock');
        $reservation = $this->store->createReservation(['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'cart_id' => $cartId, 'order_id' => null, 'qty' => $qty, 'state' => 'held', 'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds), 'idempotency_key' => $idempotencyKey, 'allocation_key' => $allocationKey, 'store_id' => $storeId, 'company_id' => $companyId, 'policy' => Arr::str($evidence, 'policy', 'fail'), 'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
        $this->store->ledger(['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'delta' => -$qty, 'reason' => 'reserve', 'ref_type' => 'reservation', 'ref_id' => Arr::requiredStr($reservation, 'id'), 'actor' => 'checkout', 'created_at' => date('Y-m-d H:i:s')]);
        return $reservation;
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<int, string>
     */
    private function sourcePolicyIds(array $policy, string $key): array
    {
        $values = $policy[$key] ?? [];
        if (!is_array($values)) throw new \InvalidArgumentException($key . ' must be an array');
        $result = [];
        foreach ($values as $value) if (is_string($value) && $value !== '') $result[] = $value;
        return array_values(array_unique($result));
    }

    public function release(string $reservationId, string $reason = 'release'): void
    {
        DatabaseRetry::transaction(function () use ($reservationId, $reason): void {
            $reservation = $this->store->reservation($reservationId);
            if ($reservation === null || Arr::str($reservation, 'state') !== 'held') return;
            $variantId = Arr::requiredStr($reservation, 'variant_id'); $sourceId = Arr::str($reservation, 'source_id', 'default'); $qty = Arr::requiredInt($reservation, 'qty');
            if ($this->store->markReservation($reservationId, 'released') !== 1) return;
            $this->store->releaseReserved($variantId, $qty, $sourceId);
            $this->store->ledger(['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'delta' => $qty, 'reason' => $reason, 'ref_type' => 'reservation', 'ref_id' => $reservationId, 'actor' => 'checkout', 'created_at' => date('Y-m-d H:i:s')]);
        });
    }

    public function commit(string $reservationId, string $orderId): void
    {
        DatabaseRetry::transaction(function () use ($reservationId, $orderId): void {
            $reservation = $this->store->reservation($reservationId);
            if ($reservation === null || Arr::str($reservation, 'state') !== 'held') return;
            $variantId = Arr::requiredStr($reservation, 'variant_id'); $sourceId = Arr::str($reservation, 'source_id', 'default'); $qty = Arr::requiredInt($reservation, 'qty');
            if ($this->store->markReservation($reservationId, 'committed', $orderId) !== 1) return;
            $this->store->commitReserved($variantId, $qty, $sourceId);
            $this->store->ledger(['id' => Ids::new(), 'variant_id' => $variantId, 'source_id' => $sourceId, 'delta' => 0, 'reason' => 'commit', 'ref_type' => 'order', 'ref_id' => $orderId, 'actor' => 'checkout', 'created_at' => date('Y-m-d H:i:s')]);
        });
    }

    public function available(string $variantId, string $sourceId = 'default'): int
    {
        $item = $this->store->item($variantId, $sourceId);
        return $item === null ? 0 : Arr::int($item, 'on_hand') - Arr::int($item, 'reserved');
    }

    public function item(string $variantId, string $sourceId = 'default'): ?array { return $this->store->item($variantId, $sourceId); }
}
