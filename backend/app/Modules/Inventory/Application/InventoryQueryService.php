<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Contracts\InventoryReader;

final class InventoryQueryService
{
    public function __construct(private readonly InventoryReader $inventory)
    {
    }

    public function available(string $variantId, string $sourceId = 'default'): int
    {
        return $this->inventory->available($variantId, $sourceId);
    }
    /** @return array<string, mixed>|null */
    public function item(string $variantId, string $sourceId = 'default'): ?array { return $this->inventory->item($variantId, $sourceId); }

    /** @return array<int, array<string, mixed>> per-source stock for store pickup display */
    public function sources(string $variantId): array
    {
        $out = [];
        try {
            foreach (\App\Models\InventoryItem::query()->where('variant_id', $variantId)->get() as $item) {
                $row = \App\Support\Arr::row($item);
                $source = \App\Support\Arr::str($row, 'source_id', 'default') ?: 'default';
                $onHand = \App\Support\Arr::int($row, 'on_hand');
                $reserved = \App\Support\Arr::int($row, 'reserved');
                $out[] = ['variant_id' => $variantId, 'source_id' => $source, 'on_hand' => $onHand, 'reserved' => $reserved, 'available' => max(0, $onHand - $reserved)];
            }
        } catch (\Throwable $e) {
        }
        usort($out, static fn (array $a, array $b): int => $b['available'] <=> $a['available']);
        return $out;
    }
}
