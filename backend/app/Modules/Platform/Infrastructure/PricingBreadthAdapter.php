<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\CurrencyRate;
use App\Models\CustomerGroupPrice;
use App\Models\PriceList;
use App\Models\PriceListPrice;
use App\Models\ProductVariant;
use App\Modules\Pricing\Contracts\CurrencyConverter;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Promotion\Contracts\PromotionDecisionAudit;
use App\Support\Arr;

final class PricingBreadthAdapter implements PriceResolver, CurrencyConverter
{
    public function __construct(private readonly ?PromotionDecisionAudit $audit = null) {}

    public function resolve(string $variantId, ?string $customerGroupId = null): ?array
    {
        if ($customerGroupId !== null) {
            $groupPrice = CustomerGroupPrice::query()->where('customer_group_id', $customerGroupId)->where('variant_id', $variantId)->first();
            if ($groupPrice !== null) { $row = Arr::row($groupPrice); return $this->auditedPrice($variantId, ['unit_price_minor' => Arr::int($row, 'unit_price_minor'), 'currency' => Arr::str($row, 'currency'), 'source' => 'customer_group']); }
        }
        $lists = PriceList::query()->where('state', 'active')->orderBy('priority', 'desc')->orderBy('id', 'asc')->get();
        $fallback = null;
        foreach ($lists as $list) {
            $listRow = Arr::row($list);
            $listGroup = $listRow['customer_group_id'] ?? null;
            if ($listGroup !== null && $listGroup !== $customerGroupId) continue;
            $price = PriceListPrice::query()->where('price_list_id', Arr::str($listRow, 'id'))->where('variant_id', $variantId)->first();
            if ($price === null) continue;
            $priceRow = Arr::row($price);
            if ($listGroup !== null && $listGroup === $customerGroupId) return $this->auditedPrice($variantId, ['unit_price_minor' => Arr::int($priceRow, 'unit_price_minor'), 'currency' => Arr::str($listRow, 'currency'), 'source' => 'price_list']);
            $fallback ??= ['unit_price_minor' => Arr::int($priceRow, 'unit_price_minor'), 'currency' => Arr::str($listRow, 'currency'), 'source' => 'price_list'];
        }
        if ($fallback !== null) return $this->auditedPrice($variantId, $fallback);
        $variant = ProductVariant::find($variantId);
        if ($variant === null) return null;
        $row = Arr::row($variant);
        if (Arr::str($row, 'status', 'active') !== 'active') return null;
        $price = Arr::int($row, 'price_minor');
        if ($price < 0) throw new \InvalidArgumentException('Variant price cannot be negative');
        $currency = Arr::str($row, 'currency', 'VND');
        if (strlen($currency) !== 3) throw new \InvalidArgumentException('Variant currency must be ISO-4217');
        return $this->auditedPrice($variantId, [
            'unit_price_minor' => $price,
            'currency' => $currency,
            'source' => 'variant',
        ]);
    }

    /**
     * @param array{unit_price_minor: int, currency: string, source: string} $price
     * @return array{unit_price_minor: int, currency: string, source: string}
     */
    private function auditedPrice(string $variantId, array $price): array
    {
        if ($this->audit !== null) try { $this->audit->record(['code_hash' => hash('sha256', $variantId), 'decision' => 'price_selected', 'reason' => Arr::str($price, 'source'), 'currency' => Arr::str($price, 'currency'), 'subtotal_minor' => Arr::int($price, 'unit_price_minor'), 'discount_minor' => 0, 'context' => ['variant_id' => $variantId]]); } catch (\Throwable) { /* pricing remains authoritative if evidence storage is unavailable */ }
        return $price;
    }

    public function convert(int $amountMinor, string $fromCurrency, string $toCurrency): int
    {
        if ($fromCurrency === $toCurrency) return $amountMinor;
        $row = CurrencyRate::query()->where('base_currency', $fromCurrency)->where('quote_currency', $toCurrency)->where('state', 'active')->first();
        $inverse = false;
        if ($row === null) {
            $row = CurrencyRate::query()->where('base_currency', $toCurrency)->where('quote_currency', $fromCurrency)->where('state', 'active')->first();
            $inverse = true;
        }
        if ($row === null) throw new \InvalidArgumentException('No currency conversion rate available');
        $data = Arr::row($row);
        $numerator = Arr::int($data, 'rate_numerator');
        $denominator = Arr::int($data, 'rate_denominator');
        if ($numerator <= 0 || $denominator <= 0) throw new \InvalidArgumentException('Currency conversion rate must be positive');
        if ($inverse) [$numerator, $denominator] = [$denominator, $numerator];
        return intdiv(($amountMinor * $numerator) + intdiv($denominator, 2), $denominator);
    }
}
