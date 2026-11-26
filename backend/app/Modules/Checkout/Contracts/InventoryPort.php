<?php
declare(strict_types=1);
namespace App\Modules\Checkout\Contracts;
interface InventoryPort
{
    /** @param array<string, string>|null $scope @return array<string, mixed> */ public function reserve(string $variantId, int $qty, string $cartId, string $sourceId = 'default', ?string $idempotencyKey = null, ?array $scope = null): array;
    public function release(string $id, string $reason): void;
    public function commit(string $id, string $orderId): void;
}
