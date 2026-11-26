<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CurrencyRate;
use App\Models\CustomerGroupPrice;
use App\Models\PriceList;
use App\Models\PriceListPrice;
use App\Models\TaxRule;
use App\Modules\Platform\Infrastructure\PricingBreadthAdapter;
use App\Modules\Platform\Infrastructure\NativeTaxProvider;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Pricing\Application\PricingService;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Tax\Contracts\TaxProvider;
use App\Support\Ids;
use Tests\TestCase;

final class PricingBreadthTest extends TestCase
{
    public function testCustomerGroupPriceWinsAndConvertsThroughExplicitRate(): void
    {
        $listId = Ids::new();
        PriceList::create(['id' => $listId, 'code' => 'usd-default', 'name' => 'USD default', 'currency' => 'USD', 'priority' => 1, 'state' => 'active']);
        PriceListPrice::create(['id' => Ids::new(), 'price_list_id' => $listId, 'variant_id' => 'variant-1', 'unit_price_minor' => 100]);
        CustomerGroupPrice::create(['id' => Ids::new(), 'customer_group_id' => 'vip', 'variant_id' => 'variant-1', 'currency' => 'USD', 'unit_price_minor' => 80]);
        CurrencyRate::create(['id' => Ids::new(), 'base_currency' => 'USD', 'quote_currency' => 'VND', 'rate_numerator' => 24000, 'rate_denominator' => 1, 'state' => 'active']);

        $tax = new class implements TaxProvider { public function calculate(array $context, array $lines): array { return ['total_minor' => 0, 'lines' => [], 'rules' => []]; } };
        $shipping = new class implements RateProvider { public function quote(array $context): array { return [['amount_minor' => 0]]; } };
        $service = new PricingService(new PricingEngine($tax, $shipping), new PricingBreadthAdapter(), new PricingBreadthAdapter());
        $snapshot = $service->quote('snapshot-1', 'VND', [['variant_id' => 'variant-1', 'sku' => 'SKU-1', 'name' => 'Item', 'qty' => 2, 'unit_price_minor' => 999999]], 'vip');

        self::assertSame(3840000, $snapshot->subtotal->amountMinor);
        self::assertSame('customer_group', (new PricingBreadthAdapter())->resolve('variant-1', 'vip')['source']);
    }

    public function testExistingLinePriceRemainsTheDefaultWithoutConfiguredPricing(): void
    {
        $tax = new class implements TaxProvider { public function calculate(array $context, array $lines): array { return ['total_minor' => 0, 'lines' => [], 'rules' => []]; } };
        $shipping = new class implements RateProvider { public function quote(array $context): array { return [['amount_minor' => 0]]; } };
        $service = new PricingService(
            new PricingEngine($tax, $shipping),
            new class implements \App\Modules\Pricing\Contracts\PriceResolver { public function resolve(string $variantId, ?string $customerGroupId = null): ?array { return null; } },
            new class implements \App\Modules\Pricing\Contracts\CurrencyConverter { public function convert(int $amountMinor, string $fromCurrency, string $toCurrency): int { return $amountMinor; } },
        );
        $snapshot = $service->quote('snapshot-2', 'VND', [['variant_id' => 'variant-2', 'sku' => 'SKU-2', 'name' => 'Item', 'qty' => 1, 'unit_price_minor' => 12000]]);
        self::assertSame(12000, $snapshot->grandTotal->amountMinor);
    }

    public function testNativeTaxRuleUsesDestinationAndTaxClassAndSurvivesSnapshot(): void
    {
        TaxRule::create([
            'id' => Ids::new(), 'code' => 'vn-standard', 'destination_country' => 'VN',
            'tax_class' => 'standard', 'rate_basis_points' => 1000, 'source' => 'tax-authority',
            'evidence' => 'published VAT schedule', 'priority' => 1, 'state' => 'active',
        ]);
        $tax = new NativeTaxProvider();
        $shipping = new class implements RateProvider { public function quote(array $context): array { return [['amount_minor' => 0]]; } };
        $engine = new PricingEngine($tax, $shipping);

        $snapshot = $engine->quote('snapshot-tax', 'VND', [[
            'variant_id' => 'variant-tax', 'sku' => 'TAX-1', 'name' => 'Taxable', 'qty' => 2,
            'unit_price_minor' => 5000, 'tax_class' => 'standard',
        ]], ['destination' => ['country' => 'vn']]);

        self::assertSame(1000, $snapshot->tax->amountMinor);
        self::assertSame('vn-standard', $snapshot->rules[0]['code']);
        self::assertSame(1000, $snapshot->taxes[0]['evidence']['rate_basis_points']);
        self::assertSame('VN', $snapshot->taxes[0]['evidence']['destination']['country']);
    }

    public function testPricingPassesScopeAndDestinationToBothProviderBoundaries(): void
    {
        $seen = [];
        $tax = new class($seen) implements TaxProvider {
            public function __construct(private array &$seen) {}
            public function calculate(array $context, array $lines): array { $this->seen['tax'] = $context; return ['total_minor' => 0, 'lines' => [], 'rules' => []]; }
        };
        $shipping = new class($seen) implements RateProvider {
            public function __construct(private array &$seen) {}
            public function quote(array $context): array { $this->seen['shipping'] = $context; return [['amount_minor' => 250, 'currency' => 'USD']]; }
        };
        $engine = new PricingEngine($tax, $shipping);
        $engine->quote('snapshot-scope', 'USD', [['variant_id' => 'v-scope', 'sku' => 'SCOPE', 'name' => 'Scoped', 'qty' => 1, 'unit_price_minor' => 1000]], [
            'scope' => ['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD'],
            'destination' => ['country' => 'US'],
        ]);

        self::assertSame('store-a', $seen['tax']['context']['scope']['store_id']);
        self::assertSame('US', $seen['tax']['context']['destination']['country']);
        self::assertSame('store-a', $seen['shipping']['context']['scope']['store_id']);
        self::assertSame('US', $seen['shipping']['context']['destination']['country']);
    }

    public function testNativeTaxProviderMakesNoMatchZeroTaxPolicyExplicit(): void
    {
        $result = (new NativeTaxProvider())->calculate(['context' => ['destination' => ['country' => 'US']]], [[
            'variant_id' => 'variant-untaxed', 'line_total_minor' => 10000, 'tax_class' => 'standard',
        ]]);

        self::assertSame(0, $result['total_minor']);
        self::assertSame('explicit_zero_tax', $result['rules'][0]['policy']);
        self::assertSame('no_active_matching_rule', $result['lines'][0]['evidence']['reason']);
    }

    public function testProductionTaxFailsClosedWithoutDestination(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $this->expectException(\RuntimeException::class);
            (new NativeTaxProvider())->calculate([], [['line_total_minor' => 1000, 'tax_class' => 'standard']]);
        } finally {
            $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
        }
    }

    public function testProductionTaxFailsClosedWhenNoRuleMatchesDestination(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $this->expectException(\RuntimeException::class);
            (new NativeTaxProvider())->calculate(['destination' => ['country' => 'ZZ']], [['line_total_minor' => 1000, 'tax_class' => 'standard']]);
        } finally {
            $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
        }
    }

    public function testDefaultTaxProviderCannotRunInProduction(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $this->expectException(\RuntimeException::class);
            (new \App\Modules\Platform\Infrastructure\DefaultTaxProvider())->calculate([], []);
        } finally {
            $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
        }
    }
}
