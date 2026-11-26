<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Inventory\Contracts\InventoryAdminPort;
use App\Repositories\InventoryRepository;
final class InventoryAdminAdapter implements InventoryAdminPort
{
    public function __construct(private readonly InventoryRepository $inventory) {}
    public function setOnHand(string $variantId, int $onHand, string $actor, string $sourceId = 'default', string $idempotencyKey = ''): array { return $this->inventory->setOnHand($variantId, $onHand, $actor, $sourceId, $idempotencyKey); }
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function transfer(array $data): array { return $this->inventory->transfer($data); }
    public function setSourcePriority(string $variantId, string $sourceId, int $priority): array { return $this->inventory->setSourcePriority($variantId, $sourceId, $priority); }
}
