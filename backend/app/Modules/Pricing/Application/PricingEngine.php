<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\PricingSnapshot;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Tax\Contracts\TaxProvider;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Domain\Currency;

/**
 * Pure orchestration seam for pricing. Catalog/promotion adapters provide
 * validated line values; this class owns arithmetic and snapshot invariants.
 */
final class PricingEngine
{
    public function __construct(
        private readonly TaxProvider $tax,
        private readonly RateProvider $shipping,
        private readonly string $version = 'v2.0.0',
    ) {
    }

    /**
     * @param array<int, array{variant_id: string, sku: string, name: string, qty: int, unit_price_minor: int, tax_class?: string}> $lines
     * @param array<string, mixed> $context
     */
    public function quote(string $snapshotId, string $currency, array $lines, array $context = []): PricingSnapshot
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('Cannot price an empty cart');
        }
        $currency = Currency::normalize($currency);
        $subtotal = new Money(0, $currency);
        $normalized = [];
        foreach ($lines as $line) {
            if ($line['qty'] <= 0 || $line['unit_price_minor'] < 0) {
                throw new \InvalidArgumentException('Invalid pricing line');
            }
            $lineTotal = (new Money($line['unit_price_minor'], $currency))->multiply($line['qty'])->amountMinor;
            $subtotal = $subtotal->add(new Money($lineTotal, $currency));
            $normalized[] = $line + ['line_total_minor' => $lineTotal];
        }
        $rates = $this->shipping->quote(['lines' => $normalized, 'context' => $context]);
        $shippingMinor = $this->selectShipping($rates, $currency);
        $taxResult = $this->tax->calculate(['currency' => $currency, 'context' => $context, 'tax_inclusive' => ($context['tax_inclusive'] ?? false) === true], $normalized);
        if (($taxResult['currency'] ?? $currency) !== $currency) throw new \InvalidArgumentException('Tax currency mismatch');
        $taxMinor = $taxResult['total_minor'] ?? null;
        if (!is_int($taxMinor) && !is_numeric($taxMinor)) throw new \RuntimeException('Tax provider returned an invalid total');
        $taxMinor = (int) $taxMinor;
        if ($taxMinor < 0 || $taxMinor > Money::MAX_MINOR || !is_array($taxResult['lines'] ?? null) || !is_array($taxResult['rules'] ?? null)) throw new \RuntimeException('Tax provider returned an invalid response');
        $rules = $taxResult['rules'];
        if (is_array($rates[0]['evidence'] ?? null)) $rules[] = ['type' => 'shipping', 'code' => (string) ($rates[0]['code'] ?? 'selected'), 'evidence' => $rates[0]['evidence']];
        $zero = new Money(0, $currency);
        return new PricingSnapshot(
            $snapshotId,
            $this->version,
            $normalized,
             $subtotal,
            $zero,
            new Money($shippingMinor, $currency),
            new Money($taxMinor, $currency),
             ($taxResult['tax_inclusive'] ?? false) === true
                 ? $subtotal->add(new Money($shippingMinor, $currency))
                 : $subtotal->add(new Money($shippingMinor, $currency))->add(new Money($taxMinor, $currency)),
             $rules,
            $taxResult['lines'],
            null,
            ($taxResult['tax_inclusive'] ?? false) === true,
        );
    }

    /** @param array<int, array<string, mixed>> $rates */
    private function selectShipping(array $rates, string $expectedCurrency): int
    {
        if ($rates === []) {
            throw new \RuntimeException('No shipping rate available');
        }
        $amount = $rates[0]['amount_minor'] ?? null;
        if (!is_int($amount) && !is_numeric($amount)) {
            throw new \RuntimeException('Shipping rate has no integer amount');
        }
        $amount = (int) $amount;
        if ($amount < 0 || $amount > Money::MAX_MINOR) throw new \RuntimeException('Shipping rate is outside the supported range');
        $currency = $rates[0]['currency'] ?? null;
        if ($currency !== null && (!is_string($currency) || strlen($currency) !== 3 || Currency::normalize($currency) !== $expectedCurrency)) {
            throw new \RuntimeException('Shipping rate has an invalid currency');
        }
        return $amount;
    }
}
