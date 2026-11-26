<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Platform\Infrastructure\ReportingReadAdapter;
use App\Repositories\OrderRepository;
use Siro\Core\Cache;
use Siro\Core\Database;
use Tests\TestCase;

final class ReportingReadAdapterCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $orders = new OrderRepository();
        $orders->createOrder([
            'id' => str_pad('REP-ORDER-1', 26, '0'), 'number' => 'REP-0001', 'customer_id' => 'cust-1',
            'currency' => 'USD', 'subtotal_minor' => 5000, 'discount_minor' => 500, 'grand_total_minor' => 5000,
            'state' => 'paid', 'idempotency_key' => 'rep-1', 'store_id' => 'default', 'channel_id' => 'default',
            'created_at' => '2026-09-10 10:00:00',
        ]);
        $orders->createOrder([
            'id' => str_pad('REP-ORDER-2', 26, '0'), 'number' => 'REP-0002', 'customer_id' => 'cust-1',
            'currency' => 'USD', 'subtotal_minor' => 3000, 'discount_minor' => 0, 'grand_total_minor' => 3000,
            'state' => 'delivered', 'idempotency_key' => 'rep-2', 'store_id' => 'default', 'channel_id' => 'default',
            'created_at' => '2026-09-20 10:00:00',
        ]);
        $orders->createOrder([
            'id' => str_pad('REP-ORDER-3', 26, '0'), 'number' => 'REP-0003', 'customer_id' => 'cust-2',
            'currency' => 'EUR', 'subtotal_minor' => 2000, 'discount_minor' => 0, 'grand_total_minor' => 2000,
            'state' => 'paid', 'idempotency_key' => 'rep-3', 'store_id' => 'other', 'channel_id' => 'other',
            'created_at' => '2026-09-15 10:00:00',
        ]);
        $orders->addLine([
            'id' => 'rep-line-1', 'order_id' => str_pad('REP-ORDER-1', 26, '0'), 'variant_id' => 'v-rep-1',
            'sku' => 'REP-SKU-1', 'name' => 'Repo Product', 'qty' => 2, 'unit_price_minor' => 2500,
        ]);
        $orders->addLine([
            'id' => 'rep-line-2', 'order_id' => str_pad('REP-ORDER-2', 26, '0'), 'variant_id' => 'v-rep-2',
            'sku' => 'REP-SKU-2', 'name' => 'Repo Product Two', 'qty' => 1, 'unit_price_minor' => 3000,
        ]);
        foreach ([
            ['REP-ORDER-1', '2026-09-10 10:00:00'],
            ['REP-ORDER-2', '2026-09-20 10:00:00'],
            ['REP-ORDER-3', '2026-09-15 10:00:00'],
        ] as [$suffix, $createdAt]) {
            Database::table('orders')->where('id', str_pad($suffix, 26, '0'))->update(['created_at' => $createdAt]);
        }

        Database::table('products')->insert([
            'id' => 'rep-product-1', 'name' => 'Reporting Product', 'slug' => 'reporting-product',
            'status' => 'active', 'store_id' => 'default', 'channel_id' => 'default', 'locale' => 'en', 'currency' => 'USD',
        ]);
        Database::table('customers')->insert([
            'id' => 'cust-1', 'email' => 'reporting-customer@example.test', 'name' => 'Reporting Customer',
            'created_at' => '2026-09-01 00:00:00',
        ]);
        Database::table('inventory_items')->insert([
            'id' => 'rep-inv-1', 'variant_id' => 'v-rep-1', 'source_id' => 'default',
            'on_hand' => 10, 'reserved' => 2, 'low_threshold' => 3,
        ]);
        Database::table('commerce_jobs')->insert([
            'id' => 'rep-job-failed', 'queue' => 'default', 'name' => 'commerce.test', 'idempotency_key' => 'rep-job-key-1',
            'payload_json' => '{}', 'state' => 'failed', 'attempts' => 1, 'max_attempts' => 5, 'available_at' => '2026-09-10 10:00:00',
        ]);
        Database::table('payment_operations')->insert([
            'id' => 'rep-op-failed', 'payment_id' => 'rep-payment-1', 'type' => 'capture', 'amount_minor' => 100,
            'currency' => 'USD', 'idempotency_key' => 'rep-op-1', 'state' => 'failed', 'created_at' => '2026-09-10 10:00:00',
        ]);
        Database::table('refunds')->insert([
            'id' => 'rep-refund-1', 'order_id' => str_pad('REP-ORDER-1', 26, '0'), 'payment_id' => 'rep-payment-1',
            'amount_minor' => 500, 'minor_unit' => 2, 'tax_minor' => 0, 'evidence_json' => '{}', 'reason' => 'test',
            'currency' => 'USD', 'state' => 'succeeded', 'idempotency_key' => 'rep-refund-1', 'created_at' => '2026-09-11 10:00:00',
        ]);

        $events = [
            ['product.view', '{"properties":{"product_id":"rep-product-1"}}'],
            ['product_view', '{"properties":{"product_id":"rep-product-1"}}'],
            ['cart.add', '{"properties":{"product_id":"rep-product-1"}}'],
            ['checkout.start', '{}'],
            ['order.placed', '{}'],
            ['wishlist_add', '{"properties":{"product_id":"rep-product-1"}}'],
            ['wishlist_remove', '{"properties":{"product_id":"rep-product-1"}}'],
            ['recommendation_click', '{}'],
            ['unknown.event', 'not-json'],
        ];
        foreach ($events as $index => [$event, $payload]) {
            Database::table('analytics_events')->insert([
                'id' => 'rep-event-' . $index, 'event_id' => 'rep-event-id-' . $index, 'schema_version' => 1,
                'event' => $event, 'payload_json' => $payload, 'store_id' => 'default', 'channel_id' => 'default',
                'locale' => 'en', 'currency' => 'USD', 'created_at' => '2026-09-10 10:00:00',
            ]);
        }
    }

    public function testOverviewFailuresAndSearchPaths(): void
    {
        $reader = new ReportingReadAdapter();

        $overview = $reader->overview('2026-09-01', '2026-09-30', 'default', 'default');
        self::assertSame(2, $overview['orders']);
        self::assertSame(8000, $overview['sales_minor']);
        self::assertSame(500, $overview['refunds_minor']);
        self::assertSame(['USD' => 500], $overview['refunds_minor_by_currency']);
        self::assertSame(['delivered' => 1, 'paid' => 1], $overview['by_state']);
        self::assertSame(0, $overview['inventory']['items']);
        self::assertSame(1, $overview['failures']['jobs']);

        $unscoped = $reader->overview('2026-09-01', '2026-09-30');
        self::assertSame(3, $unscoped['orders']);
        self::assertNull($unscoped['sales_minor']);
        self::assertSame(500, $unscoped['refunds_minor']);
        self::assertSame(1, $unscoped['inventory']['items']);
        self::assertCount(2, $unscoped['sales_minor_by_currency']);

        try {
            $reader->overview('2026-09-30', '2026-09-01');
            self::fail('Expected invalid reporting window to be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Reporting window', $error->getMessage());
        }

        $failures = $reader->failures(1, 20);
        self::assertGreaterThanOrEqual(1, $failures['meta']['total']);

        self::assertSame([], $reader->search('', 'catalog', 1, 20, 'default', 'default')['data']);

        $catalog = $reader->search('Reporting', 'catalog', 1, 20, 'default', 'default');
        self::assertSame('catalog', $catalog['type']);
        self::assertSame('rep-product-1', $catalog['data'][0]['id'] ?? null);

        $orders = $reader->search('REP-0001', 'orders', 1, 20, 'default', 'default');
        self::assertSame('REP-0001', $orders['data'][0]['number'] ?? null);

        $customers = $reader->search('Reporting', 'customers', 1, 20, 'default', 'default');
        self::assertSame('r***@example.test', $customers['data'][0]['email_masked'] ?? null);

        $fallback = $reader->search('Reporting', 'unexpected-type', 1, 20, 'default', 'default');
        self::assertSame('catalog', $fallback['type']);
    }

    public function testRefundsScopedByStoreAndChannelWithoutMixedPlaceholders(): void
    {
        Database::table('refunds')->insert([
            'id' => 'rep-refund-other', 'order_id' => str_pad('REP-ORDER-3', 26, '0'), 'payment_id' => 'rep-payment-other',
            'amount_minor' => 700, 'minor_unit' => 2, 'tax_minor' => 0, 'evidence_json' => '{}', 'reason' => 'test',
            'currency' => 'EUR', 'state' => 'succeeded', 'idempotency_key' => 'rep-refund-other', 'created_at' => '2026-09-16 10:00:00',
        ]);
        $reader = new ReportingReadAdapter();

        $default = $reader->overview('2026-09-01', '2026-09-30', 'default', 'default');
        self::assertSame(500, $default['refunds_minor']);
        self::assertSame(['USD' => 500], $default['refunds_minor_by_currency']);

        $other = $reader->overview('2026-09-01', '2026-09-30', 'other', 'other');
        self::assertSame(700, $other['refunds_minor']);
        self::assertSame(['EUR' => 700], $other['refunds_minor_by_currency']);

        $all = $reader->overview('2026-09-01', '2026-09-30');
        self::assertNull($all['refunds_minor']);
        self::assertSame(['EUR' => 700, 'USD' => 500], $all['refunds_minor_by_currency']);
    }

    public function testAdvancedDimensionsProduceEvidence(): void
    {
        $reader = new ReportingReadAdapter();

        $profitability = $reader->advanced('profitability', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame('partial', $profitability['data']['status']);
        self::assertSame(3, $profitability['data']['orders']);
        self::assertSame(10000, $profitability['data']['revenue_minor']);
        self::assertArrayHasKey('cogs', $profitability['data']['unavailable']);

        $cohorts = $reader->advanced('cohorts', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame('2026-09', $cohorts['data']['rows'][0]['cohort'] ?? null);
        self::assertSame(2, $cohorts['data']['rows'][0]['customers'] ?? null);

        $retention = $reader->advanced('retention', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame(2, $retention['data']['observed_customers']);

        $repeat = $reader->advanced('repeat-purchase', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame(2, $repeat['data']['identified_customers']);
        self::assertSame(1, $repeat['data']['repeat_customers']);
        self::assertEqualsWithDelta(50.0, $repeat['data']['repeat_rate_percent'], 0.001);

        $attribution = $reader->advanced('attribution', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame(9, array_sum($attribution['data']['events_by_type']));

        $sla = $reader->advanced('sla', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame('unavailable', $sla['data']['status']);
        self::assertSame(3, $sla['data']['orders_observed']);

        $turnover = $reader->advanced('inventory-turnover', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame(3, $turnover['data']['units_sold']);
        self::assertSame(10, $turnover['data']['on_hand_units']);

        $widgets = $reader->advanced('widgets', '2026-09-01 00:00:00', '2026-09-30 23:59:59', 'default', 'default');
        self::assertSame('observed', $widgets['data']['status']);
        self::assertSame(8000, $widgets['data']['widgets']['revenue']['value_minor']);

        $insights = $reader->advanced('insights', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame('observed', $insights['data']['status']);
        self::assertSame(3, $insights['data']['authoritative']['orders']);
        self::assertSame(2, $insights['data']['funnel_estimate']['counts']['product_view']);
        self::assertSame(1, $insights['data']['funnel_estimate']['counts']['cart_add']);
        self::assertSame(1, $insights['data']['engagement_estimate']['wishlist_add']);
        self::assertSame('REP-SKU-1', $insights['data']['product_performance']['order_lines'][0]['sku'] ?? null);
        self::assertSame('rep-product-1', $insights['data']['product_performance']['engagement_signals'][0]['product_id'] ?? null);

        $unsupported = $reader->advanced('bogus', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
        self::assertSame('unavailable', $unsupported['data']['status']);
    }
}

