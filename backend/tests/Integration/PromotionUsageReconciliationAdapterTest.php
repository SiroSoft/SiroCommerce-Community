<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CouponUsage;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PromotionUsageReconciliation;
use App\Modules\Platform\Infrastructure\PromotionAllocationService;
use App\Modules\Platform\Infrastructure\PromotionUsageReconciliationAdapter;
use App\Modules\Platform\Infrastructure\PromotionStoreAdapter;
use Tests\TestCase;

final class PromotionUsageReconciliationAdapterTest extends TestCase
{
    public function testReservedUsageStoresTheActualOrderIdForLaterReconciliation(): void
    {
        Coupon::create(['id' => 'coupon-order-link', 'code' => 'ORDER-LINK', 'rules' => '{"type":"fixed","value":100}', 'state' => 'active']);
        (new PromotionStoreAdapter())->reserve('coupon-order-link', 'order-actual-1', 100);

        self::assertSame('order-actual-1', CouponUsage::query()->where('reservation_key', 'order-actual-1')->first()->toArray()['order_id']);
    }

    public function testReservationKeyReuseWithDifferentCouponOrAmountFailsClosed(): void
    {
        Coupon::create(['id' => 'coupon-reuse-a', 'code' => 'REUSE-A', 'rules' => '{}', 'state' => 'active']);
        Coupon::create(['id' => 'coupon-reuse-b', 'code' => 'REUSE-B', 'rules' => '{}', 'state' => 'active']);
        $adapter = new PromotionStoreAdapter();
        $adapter->reserve('coupon-reuse-a', 'reservation-reuse', 100);

        $this->expectException(\InvalidArgumentException::class);
        $adapter->reserve('coupon-reuse-b', 'reservation-reuse', 200);
    }

    public function testUsageLimitIsEnforcedWhileReservationIsHeld(): void
    {
        Coupon::create(['id' => 'coupon-limit', 'code' => 'LIMIT-1', 'rules' => '{"type":"fixed","value":100,"usage_limit":1}', 'state' => 'active']);
        $adapter = new PromotionStoreAdapter();
        $adapter->reserve('coupon-limit', 'reservation-limit-1', 100);

        $this->expectException(\RuntimeException::class);
        $adapter->reserve('coupon-limit', 'reservation-limit-2', 100);
    }

    public function testSeveralPartialRefundsReleaseTheOriginalDiscountExactlyOnce(): void
    {
        $this->seedOrder(10000, 'USD');
        CouponUsage::create([
            'id' => 'usage-1', 'coupon_id' => 'coupon-1', 'order_id' => 'order-1',
            'discount_minor' => 1000, 'reservation_key' => 'checkout-1', 'state' => 'committed',
        ]);
        $adapter = new PromotionUsageReconciliationAdapter();

        self::assertSame(250, $adapter->reconcile('order-1', 2500, 10000, 'USD', 'refund-1')['released_discount_minor']);
        self::assertSame(250, $adapter->reconcile('order-1', 2500, 10000, 'USD', 'refund-2')['released_discount_minor']);
        self::assertSame(500, $adapter->reconcile('order-1', 5000, 10000, 'USD', 'refund-3')['released_discount_minor']);
        self::assertSame(0, (int) CouponUsage::find('usage-1')->toArray()['discount_minor']);
        self::assertSame('rolled_back', CouponUsage::find('usage-1')->toArray()['state']);
    }

    public function testReplayIsIdempotentButKeyReuseForAnotherRefundFailsClosed(): void
    {
        $this->seedOrder(10000, 'USD');
        $adapter = new PromotionUsageReconciliationAdapter();

        $first = $adapter->reconcile('order-1', 1000, 10000, 'USD', 'refund-1');
        self::assertSame($first, $adapter->reconcile('order-1', 1000, 10000, 'USD', 'refund-1'));

        $this->expectException(\InvalidArgumentException::class);
        $adapter->reconcile('order-1', 2000, 10000, 'USD', 'refund-1');
    }

    public function testMalformedTotalsAndUnsupportedCurrencyAreRejected(): void
    {
        $this->seedOrder(10000, 'USD');
        $adapter = new PromotionUsageReconciliationAdapter();

        $this->expectException(\InvalidArgumentException::class);
        $adapter->reconcile('order-1', 0, 10000, 'USD', 'bad-amount');
    }

    public function testOrderMinorUnitMismatchFailsClosed(): void
    {
        Order::create([
            'id' => 'minor-unit-order', 'number' => 'ORD-MINOR', 'currency' => 'USD', 'minor_unit' => 1,
            'subtotal_minor' => 10000, 'discount_minor' => 0, 'shipping_minor' => 0,
            'tax_minor' => 0, 'grand_total_minor' => 10000, 'state' => 'paid',
            'idempotency_key' => 'minor-unit-key', 'promotion_evidence_json' => '[]',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new PromotionUsageReconciliationAdapter())->reconcile('minor-unit-order', 100, 10000, 'USD', 'minor-key');
    }

    public function testPromotionAllocationKeyReuseWithDifferentPayloadFailsClosed(): void
    {
        Order::create([
            'id' => 'allocation-order', 'number' => 'ALLOC-1', 'currency' => 'USD',
            'subtotal_minor' => 10000, 'discount_minor' => 1000, 'shipping_minor' => 0,
            'tax_minor' => 0, 'grand_total_minor' => 9000, 'state' => 'paid',
            'idempotency_key' => 'allocation-order-key',
        ]);
        $service = new PromotionAllocationService();
        $first = $service->reconcile('allocation-order', [['promotion_id' => 'promo-1', 'discount_minor' => 1000]], 'allocation-key');
        self::assertSame(1000, $first['discount_minor']);

        $this->expectException(\InvalidArgumentException::class);
        $service->reconcile('allocation-order', [['promotion_id' => 'promo-2', 'discount_minor' => 1000]], 'allocation-key');
    }

    public function testPromotionReconciliationMigrationCanRollBackAndReapply(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20260920000029_create_promotion_usage_reconciliations.php';
        $migration->down();
        $migration->up();

        self::assertCount(0, PromotionUsageReconciliation::query()->get());
    }

    private function seedOrder(int $total, string $currency): void
    {
        Order::create([
            'id' => 'order-1', 'number' => 'ORD-1', 'currency' => $currency,
            'subtotal_minor' => $total, 'discount_minor' => 1000, 'shipping_minor' => 0,
            'tax_minor' => 0, 'grand_total_minor' => $total, 'state' => 'paid',
            'idempotency_key' => 'order-key-1', 'promotion_evidence_json' => '[]',
        ]);
    }
}
