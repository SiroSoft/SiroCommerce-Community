<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Inventory\Contracts\InventoryStore;
use App\Repositories\InventoryRepository;
final class InventoryStoreAdapter implements InventoryStore
{
    public function __construct(private readonly InventoryRepository $inventory) {}
    public function item(string $variantId, string $sourceId = 'default'): ?array { return $this->inventory->findItem($variantId, $sourceId); }
    public function tryReserve(string $variantId, int $qty, string $sourceId = 'default'): int { return $this->inventory->tryReserve($variantId, $qty, $sourceId); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createReservation(array $data): array { return $this->inventory->createReservation($data); }
    public function reservation(string $id): ?array { return $this->inventory->findReservation($id); }
    public function reservationByIdempotency(string $key): ?array { return $this->inventory->findReservationByIdempotency($key); }
    public function reservationsByAllocationKey(string $key): array { return $this->inventory->findReservationsByAllocationKey($key); }
    public function releaseReserved(string $variantId, int $qty, string $sourceId = 'default'): void { $this->inventory->releaseReserved($variantId, $qty, $sourceId); }
    public function commitReserved(string $variantId, int $qty, string $sourceId = 'default'): void { $this->inventory->commitReserved($variantId, $qty, $sourceId); }
    public function markReservation(string $id, string $state, ?string $orderId = null): int { return $this->inventory->markReservation($id, $state, $orderId); }
    public function ledger(array $data): void { $this->inventory->appendLedger($data); }
    public function receiveReturn(string $variantId, int $qty, string $disposition, string $returnId, string $lineId, string $actor, string $sourceId = 'default'): void { $this->inventory->receiveReturn($variantId, $qty, $disposition, $returnId, $lineId, $actor, $sourceId); }
    public function sourceAvailability(string $variantId, ?string $storeId = null, ?string $companyId = null): array { return $this->inventory->sourceAvailability($variantId, $storeId, $companyId); }
    /** @param array<int, string> $sourceIds */
    public function lockSources(string $variantId, array $sourceIds): void { $this->inventory->lockSources($variantId, $sourceIds); }
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function transfer(array $data): array { return $this->inventory->transfer($data); }
    /** @return array<string,mixed> */ public function adjustOnHand(string $variantId, int $onHand, string $actor, string $sourceId, string $evidence): array { return $this->inventory->adjustOnHand($variantId, $onHand, $actor, $sourceId, $evidence); }
    /** @return array<string,mixed> */ public function setSourcePriority(string $variantId, string $sourceId, int $priority): array { return $this->inventory->setSourcePriority($variantId, $sourceId, $priority); }
}
