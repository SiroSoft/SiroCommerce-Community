<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Contracts\CurrencyConverter;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\Domain\PricingSnapshot;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use App\Support\Arr;

/** Optional pricing breadth facade; the engine remains the arithmetic authority. */
final class PricingService
{
    public function __construct(
        private readonly PricingEngine $engine,
        private readonly PriceResolver $prices,
        private readonly CurrencyConverter $currency,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $context
     */
    public function quote(string $snapshotId, string $currency, array $lines, ?string $customerGroupId = null, array $context = []): PricingSnapshot
    {
        /** @var array<int, array{variant_id: string, sku: string, name: string, qty: int, unit_price_minor: int, tax_class?: string}> $resolved */
        $resolved = [];
        foreach ($lines as $line) {
            $variantId = Arr::requiredStr($line, 'variant_id');
            $next = [
                'variant_id' => $variantId,
                'sku' => Arr::str($line, 'sku'),
                'name' => Arr::str($line, 'name'),
                'qty' => Arr::requiredInt($line, 'qty'),
                'unit_price_minor' => Arr::requiredInt($line, 'unit_price_minor'),
            ];
            $taxClass = Arr::str($line, 'tax_class');
            if ($taxClass !== '') $next['tax_class'] = $taxClass;
            $price = $this->prices->resolve($variantId, $customerGroupId);
            if ($price !== null) {
                $sourceCurrency = $price['currency'];
                $next['unit_price_minor'] = $sourceCurrency === $currency
                    ? $price['unit_price_minor']
                    : $this->currency->convert($price['unit_price_minor'], $sourceCurrency, $currency);
            }
            $resolved[] = $next;
        }
        $pricingContext = $context;
        $pricingContext['customer_group_id'] = $customerGroupId;
        // Modules may adjust resolved lines (member tiers, campaign overrides);
        // a veto aborts the quote with the handler's reason.
        $hooked = Hooks::fire(HookName::PricingBeforeQuote, ['currency' => $currency, 'lines' => $resolved, 'customer_group_id' => $customerGroupId, 'context' => $pricingContext]);
        $lines = (isset($hooked['lines']) && is_array($hooked['lines']) && $hooked['lines'] !== []) ? array_values($hooked['lines']) : $resolved;
        return $this->engine->quote($snapshotId, $currency, $lines, $pricingContext);
    }
}
