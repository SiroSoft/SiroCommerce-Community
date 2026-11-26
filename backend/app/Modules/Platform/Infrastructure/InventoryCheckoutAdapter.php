<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Checkout\Contracts\InventoryPort;
use App\Modules\Inventory\Application\InventoryManager;
final class InventoryCheckoutAdapter implements InventoryPort
{
    public function __construct(private readonly InventoryManager $inventory) {}
    public function reserve(string $variantId, int $qty, string $cartId, string $sourceId = 'default', ?string $idempotencyKey = null, ?array $scope = null): array { return $this->inventory->reserve($variantId, $qty, $cartId, 900, $sourceId, $idempotencyKey, $scope); }
    public function release(string $id, string $reason): void { $this->inventory->release($id, $reason); }
    public function commit(string $id, string $orderId): void { $this->inventory->commit($id, $orderId); }
}
