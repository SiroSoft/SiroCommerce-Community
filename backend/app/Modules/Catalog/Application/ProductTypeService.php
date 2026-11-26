<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogAdminPort;
use App\Modules\Catalog\Domain\ProductTypeDefinition;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Inventory\Contracts\InventoryStore;
use App\Support\Arr;

final class ProductTypeService
{
    public function __construct(private readonly CatalogAdminPort $catalog, private readonly PriceResolver $prices, private readonly ?InventoryStore $inventory = null) {}

    /** @return array<string, mixed> */
    public function quoteBundle(string $productId, int $qty, string $currency = 'VND'): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('Bundle quantity must be positive');
        $components = $this->catalog->components($productId);
        $components = ProductTypeDefinition::normalizeComponents($components);
        $mode = Arr::str($components[0], 'bundle_pricing_mode', 'component_sum');
        ProductTypeDefinition::forBundle($mode, $components);
        $fixed = $mode === 'fixed' ? Arr::int($components[0], 'bundle_price_minor', -1) : -1;
        if ($mode === 'fixed' && $fixed < 0) throw new \RuntimeException('Fixed bundle price is unavailable');
        $lines = [];
        $total = 0;
        foreach ($components as $component) {
            $componentQty = Arr::requiredInt($component, 'qty');
            if ($componentQty <= 0) throw new \InvalidArgumentException('Bundle component quantity must be positive');
            $variantId = $this->availableVariant($component, $componentQty * $qty);
            if ($mode === 'fixed') {
                $lines[] = ['component_variant_id' => $variantId, 'qty' => $componentQty * $qty, 'unit_price_minor' => 0, 'currency' => $currency, 'source' => 'fixed_bundle'];
                continue;
            }
            $price = $this->prices->resolve($variantId);
            if ($price === null) throw new \RuntimeException('Bundle component price is unavailable');
            if ($price['currency'] !== $currency) throw new \InvalidArgumentException('Bundle components must use one currency');
            $lineQty = $componentQty * $qty;
            $lineTotal = $price['unit_price_minor'] * $lineQty;
            $total += $lineTotal;
            $lines[] = ['component_variant_id' => $variantId, 'qty' => $lineQty, 'unit_price_minor' => $price['unit_price_minor'], 'currency' => $price['currency'], 'source' => $price['source']];
        }
        if ($mode === 'fixed') {
            $total = $fixed * $qty;
            return ['product_id' => $productId, 'qty' => $qty, 'currency' => $currency, 'pricing_mode' => 'fixed', 'unit_price_minor' => $fixed, 'total_minor' => $total, 'components' => $lines];
        }
        return ['product_id' => $productId, 'qty' => $qty, 'currency' => $currency, 'pricing_mode' => 'component_sum', 'unit_price_minor' => intdiv($total, $qty), 'total_minor' => $total, 'components' => $lines];
    }

    /** Select only an explicitly permitted substitute and never silently substitute. */
    /** @param array<string, mixed> $component */
    private function availableVariant(array $component, int $requiredQty): string
    {
        $primary = Arr::requiredStr($component, 'component_variant_id');
        $candidates = [$primary];
        $raw = $component['substitution_variant_ids'] ?? [];
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (is_array($raw)) foreach ($raw as $candidate) if (is_string($candidate) && $candidate !== '') $candidates[] = $candidate;
        $candidates = array_values(array_unique($candidates));
        $policy = Arr::str($component, 'substitution_policy', 'none');
        if ($this->inventory === null) {
            if ($policy !== 'none' && count($candidates) > 1) throw new \RuntimeException('Bundle inventory validation is unavailable');
            return $primary;
        }
        foreach ($candidates as $candidate) {
            $available = 0;
            foreach ($this->inventory->sourceAvailability($candidate) as $source) $available += max(0, Arr::int($source, 'available'));
            if ($available >= $requiredQty) return $candidate;
            if ($candidate === $primary && $policy === 'none') break;
        }
        throw new \RuntimeException('Bundle component is unavailable');
    }
}
