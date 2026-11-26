<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

interface InventoryReader
{
    public function available(string $variantId, string $sourceId = 'default'): int;
    /** @return array<string, mixed>|null */ public function item(string $variantId, string $sourceId = 'default'): ?array;
}
