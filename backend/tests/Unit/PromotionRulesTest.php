<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Promotion\Application\PromotionService;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use PHPUnit\Framework\TestCase;

final class PromotionRulesTest extends TestCase
{
    public function testSupportedRulesProduceDeterministicItemEvidence(): void
    {
        $service = $this->service(['type' => 'percentage', 'value' => 10, 'scope' => 'item', 'variant_ids' => ['v-1'], 'currency' => 'USD', 'min_spend_minor' => 1000]);
        $result = $service->preview('ITEM10', 3000, 'USD', [['variant_id' => 'v-1', 'qty' => 1, 'unit_price_minor' => 2000], ['variant_id' => 'v-2', 'qty' => 1, 'unit_price_minor' => 1000]]);
        self::assertSame(200, $result['discount_minor']);
        self::assertSame(2000, $result['eligible_minor']);
        self::assertSame('item', $result['scope']);
    }

    public function testExpiredCurrencyAndUnauthorizedRulesFailClosed(): void
    {
        $service = $this->service(['type' => 'fixed', 'value' => 10, 'ends_at' => '2000-01-01 00:00:00', 'currency' => 'USD', 'customer_group_id' => 'vip']);
        self::assertSame('Promotion is outside its date window', $service->preview('SALE', 100, 'USD', [], ['customer_group_id' => 'vip'])['error']);

        $service = $this->service(['type' => 'fixed', 'value' => 10, 'currency' => 'USD', 'customer_group_id' => 'vip']);
        self::assertSame('Promotion currency mismatch', $service->preview('SALE', 100, 'VND', [], ['customer_group_id' => 'vip'])['error']);
        self::assertSame('Promotion is not authorized for this customer group', $service->preview('SALE', 100, 'USD', [], ['customer_group_id' => 'other'])['error']);
    }

    public function testReservationCommitAndRollbackAreIdempotentAtThePortBoundary(): void
    {
        $usage = new class implements PromotionUsageWriter {
            /** @var array<string, int> */ public array $states = [];
            public function record(string $couponId, string $orderId, int $discountMinor): void {}
            public function reserve(string $couponId, string $key, int $discountMinor): void { $this->states[$key] = 1; }
            public function commit(string $key): void { if (isset($this->states[$key])) $this->states[$key] = 2; }
            public function rollback(string $key): void { if (isset($this->states[$key])) $this->states[$key] = 3; }
        };
        $service = new PromotionService($this->reader(['type' => 'fixed', 'value' => 10]), $usage);
        $service->reserveUsage('coupon-1', 'checkout-1', 10);
        $service->reserveUsage('coupon-1', 'checkout-1', 10);
        $service->rollbackUsage('checkout-1');
        $service->rollbackUsage('checkout-1');
        self::assertSame(3, $usage->states['checkout-1']);
    }

    public function testInvalidMoneyAndPercentageBoundariesFailClosed(): void
    {
        self::assertSame('Promotion subtotal cannot be negative', $this->service(['type' => 'fixed', 'value' => 1])->preview('BAD', -1)['error']);
        self::assertSame('Promotion value is invalid', $this->service(['type' => 'percentage', 'value' => 101])->preview('BAD', 100)['error']);
        self::assertSame('Promotion value is invalid', $this->service(['type' => 'fixed', 'value' => -1])->preview('BAD', 100)['error']);
        self::assertSame('Promotion line is invalid', $this->service(['scope' => 'item', 'variant_ids' => ['v-1'], 'value' => 1])->preview('BAD', 100, 'VND', [['variant_id' => 'v-1', 'qty' => 0, 'unit_price_minor' => 100]])['error']);
    }

    public function testExclusivePromotionCannotBeAddedToExclusiveEvidence(): void
    {
        $service = $this->service(['type' => 'fixed', 'value' => 10]);
        $result = $service->preview('SECOND', 100, 'VND', [], ['applied_promotions' => [['exclusive' => true]]]);
        self::assertSame('Promotion cannot be stacked', $result['error']);
    }

    /** @param array<string, mixed> $rules */
    private function service(array $rules): PromotionService
    {
        return new PromotionService($this->reader($rules), new class implements PromotionUsageWriter { public function record(string $couponId, string $orderId, int $discountMinor): void {} });
    }

    /** @param array<string, mixed> $rules */
    private function reader(array $rules): PromotionReader
    {
        return new class($rules) implements PromotionReader {
            /** @param array<string, mixed> $rules */ public function __construct(private array $rules) {}
            public function findActive(string $code): ?array { return ['id' => 'coupon-1', 'rules' => json_encode($this->rules, JSON_THROW_ON_ERROR)]; }
        };
    }
}
