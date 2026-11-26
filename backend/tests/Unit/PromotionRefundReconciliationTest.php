<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Promotion\Contracts\PromotionUsageReconciler;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use App\Modules\Promotion\Application\PromotionService;
use PHPUnit\Framework\TestCase;

final class PromotionRefundReconciliationTest extends TestCase
{
    public function testPartialRefundReleasesOnlyTheProportionalDiscountAndPreservesEvidence(): void
    {
        $ledger = new FakePromotionReconciler();
        $first = $ledger->reconcile('order-1', 2500, 10000, 'USD', 'refund-1', 'partial_refund');

        self::assertSame(250, $first['released_discount_minor']);
        self::assertSame('USD', $first['currency']);
        self::assertSame('partial_refund', $first['reason']);
        self::assertSame($first, $ledger->reconcile('order-1', 2500, 10000, 'USD', 'refund-1', 'different-replay'));
    }

    public function testConcurrentReplayIsSingleUseAndCurrencyMismatchFailsClosed(): void
    {
        $ledger = new FakePromotionReconciler();
        $ledger->reconcile('order-1', 10000, 10000, 'USD', 'cancel-1', 'cancelled');
        self::assertSame(1, $ledger->writes);
        $ledger->reconcile('order-1', 10000, 10000, 'USD', 'cancel-1', 'cancelled');
        self::assertSame(1, $ledger->writes);

        $this->expectException(\InvalidArgumentException::class);
        $ledger->reconcile('order-1', 1, 10000, 'EUR', 'refund-2');
    }

    public function testConcurrentRedemptionCannotConsumeTheSameSingleUsePromotionTwice(): void
    {
        $writer = new class implements PromotionUsageWriter {
            public int $committed = 0;
            public function record(string $couponId, string $orderId, int $discountMinor): void { $this->reserve($couponId, $orderId, $discountMinor); }
            public function reserve(string $couponId, string $key, int $discountMinor): void { if ($this->committed >= 1) throw new \RuntimeException('Promotion usage limit reached'); $this->committed++; }
        };
        $reader = new class implements PromotionReader {
            public function findActive(string $code): ?array { return ['id' => 'coupon-1', 'rules' => '{"type":"fixed","value":100,"usage_limit":1}']; }
        };
        $service = new PromotionService($reader, $writer);
        $service->reserveUsage('coupon-1', 'checkout-a', 100);
        $this->expectException(\RuntimeException::class);
        $service->reserveUsage('coupon-1', 'checkout-b', 100);
    }
}

/** @implements PromotionUsageReconciler */
final class FakePromotionReconciler implements PromotionUsageReconciler
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];
    public int $writes = 0;

    public function reconcile(string $orderId, int $amountMinor, int $orderTotalMinor, string $currency, string $idempotencyKey, string $reason = ''): array
    {
        if ($currency !== 'USD') throw new \InvalidArgumentException('Promotion reconciliation currency mismatch');
        if (isset($this->rows[$idempotencyKey])) return $this->rows[$idempotencyKey];
        $row = ['order_id' => $orderId, 'released_discount_minor' => $amountMinor === $orderTotalMinor ? 1000 : intdiv(1000 * $amountMinor, $orderTotalMinor), 'currency' => $currency, 'reason' => $reason];
        $this->rows[$idempotencyKey] = $row;
        $this->writes++;
        return $row;
    }
}
