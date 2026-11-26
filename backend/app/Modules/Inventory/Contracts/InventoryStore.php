<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Contracts;
interface InventoryStore
{
    /** @return array<string, mixed>|null */ public function item(string $variantId, string $sourceId = 'default'): ?array;
    public function tryReserve(string $variantId, int $qty, string $sourceId = 'default'): int;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createReservation(array $data): array;
    /** @return array<string, mixed>|null */ public function reservation(string $id): ?array;
    /** @return array<string, mixed>|null */ public function reservationByIdempotency(string $key): ?array;
    /** @return array<int, array<string, mixed>> */ public function reservationsByAllocationKey(string $key): array;
    public function releaseReserved(string $variantId, int $qty, string $sourceId = 'default'): void;
    public function commitReserved(string $variantId, int $qty, string $sourceId = 'default'): void;
    public function markReservation(string $id, string $state, ?string $orderId = null): int;
    /** @param array<string, mixed> $data */ public function ledger(array $data): void;
    public function receiveReturn(string $variantId, int $qty, string $disposition, string $returnId, string $lineId, string $actor, string $sourceId = 'default'): void;

    /** @return array<int, array<string, mixed>> */
    public function sourceAvailability(string $variantId, ?string $storeId = null, ?string $companyId = null): array;
    /** @param array<int, string> $sourceIds Lock rows in deterministic order; caller owns the transaction. */
    public function lockSources(string $variantId, array $sourceIds): void;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function transfer(array $data): array;

    /** @return array<string, mixed> */
    public function adjustOnHand(string $variantId, int $onHand, string $actor, string $sourceId, string $evidence): array;

    /** @return array<string, mixed> */
    public function setSourcePriority(string $variantId, string $sourceId, int $priority): array;
}
