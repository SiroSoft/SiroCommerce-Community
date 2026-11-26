<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\InventoryItem;
use App\Models\Store;
use App\Models\Channel;
use App\Modules\Platform\Infrastructure\ReportingReadAdapter;
use App\Modules\Reporting\Application\ReportingService;
use Siro\Core\Database;
use Tests\TestCase;

final class AdvancedReportingContractTest extends TestCase
{
    public function testProfitabilityDoesNotInventCogs(): void
    {
        Order::query()->insert(['id' => 'advanced-order-1', 'number' => 'ADV-1', 'customer_id' => 'advanced-customer-1', 'currency' => 'VND', 'subtotal_minor' => 1000, 'discount_minor' => 100, 'shipping_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 900, 'state' => 'paid', 'idempotency_key' => 'advanced-key-1', 'created_at' => '2026-09-20 10:00:00']);
        $result = (new ReportingService(new ReportingReadAdapter()))->advanced('profitability', '2026-09-20', '2026-09-20');
        self::assertNull($result['data']['cogs_minor']);
        self::assertSame('partial', $result['data']['status']);
        self::assertNotEmpty($result['snapshot']['id']);
    }

    public function testAdvancedDimensionsRejectUnboundedWindows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportingService(new ReportingReadAdapter()))->advanced('repeat-purchase', '2020-01-01', '2026-01-01');
    }

    public function testInventoryTurnoverBatchesOrderLineReads(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $orderId = 'turnover-order-' . $i;
            Order::query()->insert([
                'id' => $orderId,
                'number' => 'TURN-' . $i,
                'customer_id' => 'turnover-customer',
                'currency' => 'VND',
                'subtotal_minor' => 1000,
                'discount_minor' => 0,
                'shipping_minor' => 0,
                'tax_minor' => 0,
                'grand_total_minor' => 1000,
                'state' => 'paid',
                'idempotency_key' => 'turnover-key-' . $i,
                'store_id' => 'turnover-store',
                'created_at' => date('Y-m-d 10:00:00'),
            ]);
            OrderLine::query()->insert([
                'id' => 'turnover-line-' . $i,
                'order_id' => $orderId,
                'variant_id' => 'turnover-variant',
                'sku' => 'TURN-' . $i,
                'name' => 'Turnover item',
                'qty' => $i,
                'unit_price_minor' => 1000,
            ]);
        }
        InventoryItem::query()->insert(['id' => 'turnover-inventory', 'variant_id' => 'turnover-variant', 'on_hand' => 10, 'reserved' => 1, 'low_threshold' => 2]);

        Database::enableQueryCapture();
        Database::resetCapturedQueries();
        $method = new \ReflectionMethod(ReportingReadAdapter::class, 'inventoryTurnover');
        $result = $method->invoke(new ReportingReadAdapter(), [
            ['id' => 'turnover-order-1'],
            ['id' => 'turnover-order-2'],
            ['id' => 'turnover-order-3'],
        ]);

        self::assertSame(6, $result['units_sold']);
        self::assertLessThanOrEqual(2, count(Database::getCapturedQueries()));
        self::assertSame([], Database::getNPlusOneQueries());
    }

    public function testInsightsAreScopedCurrencySafeAndPrivacySafe(): void
    {
        Store::create(['id' => 'insight-store', 'code' => 'insight-store', 'name' => 'Insight Store', 'status' => 'active']);
        Store::create(['id' => 'other-store', 'code' => 'other-store', 'name' => 'Other Store', 'status' => 'active']);
        Channel::create(['id' => 'insight-web', 'store_id' => 'insight-store', 'code' => 'web', 'name' => 'Web', 'status' => 'active']);
        Channel::create(['id' => 'other-web', 'store_id' => 'other-store', 'code' => 'web', 'name' => 'Web', 'status' => 'active']);
        foreach ([['id' => 'insight-a', 'currency' => 'USD', 'store_id' => 'insight-store', 'channel_id' => 'insight-web', 'customer_id' => 'customer-a', 'total' => 1000], ['id' => 'insight-b', 'currency' => 'EUR', 'store_id' => 'other-store', 'channel_id' => 'other-web', 'customer_id' => 'customer-b', 'total' => 900]] as $row) {
            Order::query()->insert(['id' => $row['id'], 'number' => $row['id'], 'customer_id' => $row['customer_id'], 'currency' => $row['currency'], 'subtotal_minor' => $row['total'], 'discount_minor' => 0, 'shipping_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => $row['total'], 'state' => 'paid', 'idempotency_key' => $row['id'] . '-key', 'store_id' => $row['store_id'], 'channel_id' => $row['channel_id'], 'created_at' => '2026-09-20 10:00:00']);
        }
        $result = (new ReportingService(new ReportingReadAdapter()))->advanced('insights', '2026-09-20', '2026-09-20', 'insight-store', 'insight-web');
        self::assertSame(['USD' => 1000], $result['data']['authoritative']['sales_minor_by_currency']);
        self::assertSame(1, $result['data']['authoritative']['orders']);
        self::assertArrayNotHasKey('customer_id', $result['data']);
        self::assertSame(0, $result['data']['retention_estimate']['repeat_customers']);
    }

    public function testInsightsReturnEmptyAggregatesWithoutFabricatingMoney(): void
    {
        Store::create(['id' => 'insight-empty-store', 'code' => 'insight-empty-store', 'name' => 'Empty Store', 'status' => 'active']);
        Channel::create(['id' => 'insight-empty-web', 'store_id' => 'insight-empty-store', 'code' => 'web', 'name' => 'Web', 'status' => 'active']);
        $result = (new ReportingService(new ReportingReadAdapter()))->advanced('insights', '2026-09-20', '2026-09-20', 'insight-empty-store', 'insight-empty-web');
        self::assertSame([], $result['data']['authoritative']['sales_minor_by_currency']);
        self::assertSame([], $result['data']['authoritative']['refunds_minor_by_currency']);
        self::assertNull($result['data']['retention_estimate']['repeat_rate']);
    }
}
