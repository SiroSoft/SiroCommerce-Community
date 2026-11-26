<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Promotion\Application\PromotionService;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use PHPUnit\Framework\TestCase;

final class PromotionSafetyTest extends TestCase
{
    public function testPreviewRejectsCurrencyMismatchAndUsageLimit(): void
    {
        $reader = new class implements PromotionReader {
            public function findActive(string $code): ?array { return ['id' => 'coupon-1', 'rules' => '{"type":"percent","value":10,"currency":"USD","usage_limit":2}']; }
            public function usageCount(string $id): int { return 2; }
        };
        $service = new PromotionService($reader, new class implements PromotionUsageWriter {
            public function record(string $couponId, string $orderId, int $discountMinor): void {}
        });

        self::assertSame('Promotion currency mismatch', $service->preview('SAVE10', 10000, 'VND')['error']);
        self::assertSame('Promotion usage limit reached', $service->preview('SAVE10', 10000, 'USD')['error']);
    }
}
