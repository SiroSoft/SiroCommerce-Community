<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Contracts;
interface InventoryAdminPort {
    /** @return array<string,mixed> */
    public function setOnHand(string $variantId, int $onHand, string $actor, string $sourceId = 'default', string $idempotencyKey = ''): array;
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function transfer(array $data): array;
    /** @return array<string,mixed> */ public function setSourcePriority(string $variantId, string $sourceId, int $priority): array;
}
