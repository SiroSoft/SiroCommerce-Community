<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Commerce\Domain\RegionalRules;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Tax\Contracts\TaxProvider;
use PHPUnit\Framework\TestCase;

final class RegionalCommerceRulesTest extends TestCase
{
    public function testZoneSelectionIsCanonicalAndDeterministic(): void
    {
        $evidence = RegionalRules::evidence(['country' => 'vn'], ['country' => 'SG', 'postal' => '018989']);
        self::assertSame('regional', $evidence['zone']);
        self::assertSame('VN', $evidence['origin']['country']);
        self::assertSame('018989', $evidence['destination']['postal_code']);
        self::assertSame($evidence, RegionalRules::evidence(['country' => 'VN'], ['country' => 'SG', 'postal_code' => '018989']));
    }

    public function testInvalidAndUnavailableDestinationsFailClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RegionalRules::address(['country' => 'ZZ']);
    }

    public function testPricingRejectsShippingCurrencyMismatchBeforeCreatingTotals(): void
    {
        $tax = new class implements TaxProvider {
            public function calculate(array $context, array $lines): array { return ['total_minor' => 100, 'lines' => [], 'rules' => [], 'currency' => 'USD']; }
        };
        $shipping = new class implements RateProvider {
            public function quote(array $context): array { return [['code' => 'standard', 'amount_minor' => 100, 'currency' => 'EUR']]; }
        };
        $this->expectException(\RuntimeException::class);
        (new PricingEngine($tax, $shipping))->quote('regional-1', 'USD', [['variant_id' => 'v', 'sku' => 's', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 1000]]);
    }
}
