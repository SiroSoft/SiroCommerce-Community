<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\AnalyticsEvent;
use App\Models\CommerceDeadLetter;
use App\Models\CommerceJob;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\PaymentOperation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Modules\Dashboard\Infrastructure\DashboardBiRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

final class DashboardBiRepositoryTest extends TestCase
{
    private CommerceScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new CommerceScope('store-1', 'channel-1', 'en', 'VND');
    }

    public function testSalesGroupByCurrencyExcludeCancelledFailedAndKeepWindowBoundaries(): void
    {
        $this->order('usd-1', 'USD', 100, 'paid', '2026-09-20 00:00:00');
        $this->order('usd-2', 'USD', 200, 'paid', '2026-09-20 23:59:59');
        $this->order('usd-cancelled', 'USD', 999, 'cancelled', '2026-09-20 10:00:00');
        $this->order('usd-failed', 'USD', 999, 'failed', '2026-09-20 10:00:00');
        $this->order('eur-1', 'EUR', 50, 'paid', '2026-09-20 10:00:00');
        $this->order('before-window', 'USD', 500, 'paid', '2026-09-19 23:59:59');
        $this->order('after-window', 'USD', 500, 'paid', '2026-09-21 00:00:00');
        $this->order('other-store', 'USD', 500, 'paid', '2026-09-20 10:00:00', 'store-2');

        $this->refund('ref-usd', 'usd-1', 30, 'USD', 'succeeded', '2026-09-20 12:00:00');
        $this->refund('ref-eur', 'eur-1', 10, 'EUR', 'succeeded', '2026-09-20 12:00:00');
        $this->refund('ref-outside', 'after-window', 5, 'USD', 'succeeded', '2026-09-21 12:00:00');

        $blocks = (new DashboardBiRepository())->sales('2026-09-20 00:00:00', '2026-09-20 23:59:59', $this->scope);

        self::assertCount(2, $blocks);
        $byCurrency = [];
        foreach ($blocks as $block) $byCurrency[$block['currency']] = $block;

        self::assertSame(300, $byCurrency['USD']['gross_minor']);
        self::assertSame(2, $byCurrency['USD']['orders']);
        self::assertSame(150, $byCurrency['USD']['aov_minor']);
        self::assertSame(30, $byCurrency['USD']['refunds_minor']);
        self::assertSame([['date' => '2026-09-20', 'gross_minor' => 300, 'orders' => 2]], $byCurrency['USD']['by_day']);

        self::assertSame(50, $byCurrency['EUR']['gross_minor']);
        self::assertSame(1, $byCurrency['EUR']['orders']);
        self::assertSame(10, $byCurrency['EUR']['refunds_minor']);
    }

    public function testFunnelMapsAnalyticsEventsAndDistinctSessions(): void
    {
        $this->event('e1', 'product_view', 's1', '2026-09-20 10:00:00');
        $this->event('e2', 'product.view', 's2', '2026-09-20 10:01:00');
        $this->event('e3', 'cart.add', 's1', '2026-09-20 10:02:00');
        $this->event('e4', 'checkout_started', 's1', '2026-09-20 10:03:00');
        $this->event('e5', 'order.placed', 's1', '2026-09-20 10:04:00');
        $this->event('e6', 'search_submit', 's3', '2026-09-20 10:05:00');
        $this->event('e7', 'product_view', 's4', '2026-09-21 10:00:00');

        $funnel = (new DashboardBiRepository())->funnel('2026-09-20 00:00:00', '2026-09-20 23:59:59', $this->scope);

        self::assertSame(['sessions' => 3, 'product_views' => 2, 'add_to_cart' => 1, 'checkout_started' => 1, 'orders' => 1], $funnel);
    }

    public function testTopProductsRankByQuantityAndExcludeCancelledOrders(): void
    {
        $this->product('p1', 'Alpha', 'alpha');
        $this->product('p2', 'Beta', 'beta');
        $this->variant('v1', 'p1', 'SKU-A');
        $this->variant('v2', 'p2', 'SKU-B');
        $this->order('top-1', 'VND', 500, 'paid', '2026-09-20 10:00:00');
        $this->order('top-2', 'VND', 400, 'paid', '2026-09-20 11:00:00');
        $this->order('top-cancelled', 'VND', 900, 'cancelled', '2026-09-20 12:00:00');
        $this->line('line-1', 'top-1', 'v1', 'SKU-A', 'Alpha', 2, 100);
        $this->line('line-2', 'top-2', 'v2', 'SKU-B', 'Beta', 1, 200);
        $this->line('line-3', 'top-cancelled', 'v1', 'SKU-A', 'Alpha', 99, 100);

        $products = (new DashboardBiRepository())->topProducts('2026-09-20 00:00:00', '2026-09-20 23:59:59', $this->scope);

        self::assertCount(2, $products);
        self::assertSame('p1', $products[0]['product_id']);
        self::assertSame('Alpha', $products[0]['name']);
        self::assertSame('alpha', $products[0]['slug']);
        self::assertSame(2, $products[0]['qty']);
        self::assertSame(200, $products[0]['revenue_minor']);
        self::assertSame('p2', $products[1]['product_id']);
        self::assertSame(1, $products[1]['qty']);
    }

    public function testLowStockUsesFiveUnitThresholdAndReturnsTenRows(): void
    {
        $this->product('low-stock-product', 'Low stock', 'low-stock');
        for ($i = 1; $i <= 12; $i++) {
            $variantId = 'lv-' . $i;
            $this->variant($variantId, 'low-stock-product', 'LOW-' . $i);
            $available = ($i % 5) + 1;
            InventoryItem::query()->insert(['id' => 'inv-' . $i, 'variant_id' => $variantId, 'source_id' => 'default', 'on_hand' => $available, 'reserved' => 0, 'low_threshold' => 0]);
        }
        $this->variant('lv-ok', 'low-stock-product', 'LOW-OK');
        InventoryItem::query()->insert(['id' => 'inv-ok', 'variant_id' => 'lv-ok', 'source_id' => 'default', 'on_hand' => 6, 'reserved' => 0, 'low_threshold' => 0]);

        $items = (new DashboardBiRepository())->lowStock($this->scope);

        self::assertCount(10, $items);
        $previous = -1;
        foreach ($items as $item) {
            self::assertLessThanOrEqual(5, $item['available']);
            self::assertGreaterThanOrEqual($previous, $item['available']);
            $previous = $item['available'];
        }
    }

    public function testOpsCountsFailedPaymentsPendingRefundsDeadLettersAndOpenAlerts(): void
    {
        $this->order('ops-order', 'VND', 100, 'paid', '2026-09-20 10:00:00');
        Payment::query()->insert(['id' => 'pay-1', 'order_id' => 'ops-order', 'provider' => 'cod', 'amount_minor' => 100, 'state' => 'failed', 'idempotency_key' => 'pay-key']);
        PaymentOperation::query()->insert(['id' => 'op-1', 'payment_id' => 'pay-1', 'type' => 'capture', 'amount_minor' => 100, 'currency' => 'VND', 'idempotency_key' => 'op-key', 'state' => 'failed', 'created_at' => '2026-09-20 10:01:00']);
        $this->refund('pending-refund', 'ops-order', 10, 'VND', 'pending', '2026-09-20 10:02:00');
        $this->refund('done-refund', 'ops-order', 10, 'VND', 'done', '2026-09-20 10:03:00');

        CommerceJob::query()->insert(['id' => 'job-open', 'queue' => 'default', 'name' => 'job', 'idempotency_key' => 'j1', 'payload_json' => '{}', 'state' => 'failed', 'store_id' => 'store-1', 'channel_id' => 'channel-1']);
        CommerceDeadLetter::query()->insert(['id' => 'dead-open', 'job_id' => 'job-open', 'reason' => 'boom', 'payload_json' => '{}', 'failed_at' => '2026-09-20 10:00:00']);
        CommerceJob::query()->insert(['id' => 'job-resolved', 'queue' => 'default', 'name' => 'job', 'idempotency_key' => 'j2', 'payload_json' => '{}', 'state' => 'failed', 'store_id' => 'store-1', 'channel_id' => 'channel-1']);
        CommerceDeadLetter::query()->insert(['id' => 'dead-resolved', 'job_id' => 'job-resolved', 'reason' => 'boom', 'payload_json' => '{}', 'failed_at' => '2026-09-20 10:00:00', 'resolved_at' => '2026-09-20 11:00:00']);

        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO ops_anomaly_alerts (id, kind, state, window_hours, observed, baseline_x100, ratio_x100, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute(['alert-open', 'error_spike', 'open', 24, 5, 1, 500, '2026-09-20 10:00:00']);
        $pdo->prepare("INSERT INTO ops_anomaly_alerts (id, kind, state, window_hours, observed, baseline_x100, ratio_x100, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute(['alert-acked', 'latency', 'acked', 24, 5, 1, 500, '2026-09-20 10:00:00']);

        $ops = (new DashboardBiRepository())->ops($this->scope);

        self::assertSame(['failed_payments' => 1, 'pending_refunds' => 1, 'queue_dead_letters' => 1, 'open_ops_alerts' => 1], $ops);
    }

    private function order(string $id, string $currency, int $total, string $state, string $createdAt, string $store = 'store-1'): void
    {
        Order::query()->insert([
            'id' => $id,
            'number' => strtoupper($id),
            'currency' => $currency,
            'subtotal_minor' => $total,
            'grand_total_minor' => $total,
            'state' => $state,
            'idempotency_key' => $id . '-key',
            'created_at' => $createdAt,
            'store_id' => $store,
            'channel_id' => 'channel-1',
        ]);
    }

    private function refund(string $id, string $orderId, int $amount, string $currency, string $state, string $createdAt): void
    {
        Refund::query()->insert([
            'id' => $id,
            'order_id' => $orderId,
            'payment_id' => 'payment-' . $id,
            'amount_minor' => $amount,
            'currency' => $currency,
            'state' => $state,
            'created_at' => $createdAt,
        ]);
    }

    private function event(string $id, string $event, string $session, string $createdAt): void
    {
        AnalyticsEvent::query()->insert([
            'id' => $id,
            'event' => $event,
            'session_key' => $session,
            'created_at' => $createdAt,
            'store_id' => 'store-1',
            'channel_id' => 'channel-1',
        ]);
    }

    private function product(string $id, string $name, string $slug): void
    {
        Product::query()->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'status' => 'active', 'store_id' => 'store-1', 'channel_id' => 'channel-1']);
    }

    private function variant(string $id, string $productId, string $sku): void
    {
        ProductVariant::query()->insert(['id' => $id, 'product_id' => $productId, 'sku' => $sku, 'price_minor' => 100]);
    }

    private function line(string $id, string $orderId, string $variantId, string $sku, string $name, int $qty, int $unitPrice): void
    {
        OrderLine::query()->insert(['id' => $id, 'order_id' => $orderId, 'variant_id' => $variantId, 'sku' => $sku, 'name' => $name, 'qty' => $qty, 'unit_price_minor' => $unitPrice]);
    }
}
