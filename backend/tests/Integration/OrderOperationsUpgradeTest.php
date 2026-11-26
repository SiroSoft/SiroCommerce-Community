<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\FulfillmentLine;
use App\Models\FulfillmentOrder;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Modules\Fulfillment\Application\FulfillmentManager;
use App\Modules\Platform\Infrastructure\DefaultCarrier;
use App\Modules\Platform\Infrastructure\FulfillmentStoreAdapter;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Support\CommerceScope;
use Tests\TestCase;

final class OrderOperationsUpgradeTest extends TestCase
{
    public function testExplicitWarehouseAllocationBackordersAndCancellationReleasesReservation(): void
    {
        Order::create(['id' => 'upgrade-order-1', 'number' => 'UP-1', 'currency' => 'VND', 'subtotal_minor' => 300, 'grand_total_minor' => 300, 'idempotency_key' => 'upgrade-order-key']);
        OrderLine::create(['id' => 'upgrade-line-1', 'order_id' => 'upgrade-order-1', 'variant_id' => 'upgrade-variant-1', 'sku' => 'UP-1', 'name' => 'Item', 'qty' => 3, 'unit_price_minor' => 100]);
        InventoryItem::create(['id' => 'upgrade-stock-1', 'variant_id' => 'upgrade-variant-1', 'source_id' => 'warehouse-a', 'on_hand' => 2, 'reserved' => 0]);

        $service = new FulfillmentManager(new FulfillmentStoreAdapter(), new DefaultCarrier(), new InventoryStoreAdapter(new InventoryRepository()));
        $fulfillment = $service->allocate('upgrade-order-1', [['order_line_id' => 'upgrade-line-1', 'qty' => 3, 'source_id' => 'warehouse-a']], 'upgrade-allocation-key');

        self::assertSame(1, (int) $fulfillment['backorder_qty']);
        self::assertSame(2, (int) FulfillmentLine::query()->where('fulfillment_id', $fulfillment['id'])->first()->toArray()['qty']);
        self::assertSame(2, (int) InventoryItem::query()->where('variant_id', 'upgrade-variant-1')->where('source_id', 'warehouse-a')->first()->toArray()['reserved']);

        $service->cancel($fulfillment['id'], 'upgrade-cancel-key');
        self::assertSame(0, (int) InventoryItem::query()->where('variant_id', 'upgrade-variant-1')->where('source_id', 'warehouse-a')->first()->toArray()['reserved']);
    }

    public function testTimelineReadRequiresTheSameCommerceScope(): void
    {
        $orders = new OrderRepository();
        $orders->createOrder(['id' => 'upgrade-order-2', 'number' => 'UP-2', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'idempotency_key' => 'upgrade-order-key-2', 'store_id' => 'upgrade-store', 'channel_id' => 'web']);
        $orders->addTimelineEvent(['id' => 'upgrade-event-1', 'order_id' => 'upgrade-order-2', 'event_type' => 'order.transitioned', 'state' => 'paid', 'actor' => 'support@example.test', 'payload_json' => '{}', 'store_id' => 'upgrade-store', 'channel_id' => 'web', 'created_at' => date('Y-m-d H:i:s')]);

        self::assertCount(1, $orders->timeline('upgrade-order-2', new CommerceScope('upgrade-store', 'web', 'en', 'VND')));
        self::assertCount(0, $orders->timeline('upgrade-order-2', new CommerceScope('other-store', 'web', 'en', 'VND')));
    }
}
