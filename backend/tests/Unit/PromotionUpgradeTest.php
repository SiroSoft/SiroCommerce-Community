<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Promotion\Application\PromotionService;
use App\Modules\Promotion\Contracts\PromotionDecisionAudit;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use PHPUnit\Framework\TestCase;

final class PromotionUpgradeTest extends TestCase
{
    public function testScheduledCampaignAndSegmentEligibilityFailClosed(): void
    {
        $service = $this->service(['type' => 'fixed', 'value' => 10, 'campaign_state' => 'scheduled', 'customer_segment_ids' => ['vip']]);
        self::assertSame('Promotion campaign is not active', $service->preview('SALE', 100, 'VND', [], ['customer_segment_ids' => ['vip']])['error']);
        $service = $this->service(['type' => 'fixed', 'value' => 10, 'customer_segment_ids' => ['vip']]);
        self::assertSame('Promotion is not eligible for this customer segment', $service->preview('SALE', 100)['error']);
    }

    public function testRateLimitUsesHashedActorAndAuditRedactsSensitiveContext(): void
    {
        $audit = new class implements PromotionDecisionAudit { public array $row = []; public function record(array $decision): void { $this->row = $decision; } };
        $reader = new class implements PromotionReader {
            public function findActive(string $code): ?array { return ['id' => 'coupon-1', 'rules' => '{"type":"fixed","value":10,"rate_limit_window_seconds":60,"rate_limit_attempts":1}']; }
            public function attemptCount(string $couponId, string $actorHash, int $windowSeconds): int { return 1; }
        };
        $service = new PromotionService($reader, new class implements PromotionUsageWriter { public function record(string $couponId, string $orderId, int $discountMinor): void {} }, $audit);
        self::assertSame('Promotion rate limit reached', $service->preview('SALE', 100, 'VND', [], ['customer_id' => 'customer-1', 'ip' => '10.0.0.1'])['error']);
        self::assertArrayNotHasKey('ip', $audit->row['context']);
        self::assertSame(hash('sha256', 'SALE'), $audit->row['code_hash']);
    }

    /** @param array<string, mixed> $rules */
    private function service(array $rules): PromotionService
    {
        $reader = new class($rules) implements PromotionReader { public function __construct(private array $rules) {} public function findActive(string $code): ?array { return ['id' => 'coupon-1', 'rules' => json_encode($this->rules, JSON_THROW_ON_ERROR)]; } };
        return new PromotionService($reader, new class implements PromotionUsageWriter { public function record(string $couponId, string $orderId, int $discountMinor): void {} });
    }
}
