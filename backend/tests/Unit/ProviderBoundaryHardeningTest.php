<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Shared\Domain\ProviderCircuitBreaker;
use App\Modules\Shared\Domain\ProviderStatusMapper;
use App\Support\ProviderCertification;
use PHPUnit\Framework\TestCase;

final class ProviderBoundaryHardeningTest extends TestCase
{
    public function testProviderStatusesAreMappedAndUnknownValuesFailClosed(): void
    {
        self::assertSame('captured', ProviderStatusMapper::mapPayment('succeeded'));
        self::assertSame('voided', ProviderStatusMapper::mapPayment('cancelled'));
        self::assertNull(ProviderStatusMapper::mapPayment('looks_successful'));
        self::assertSame('delivered', ProviderStatusMapper::mapShipping('DELIVERED'));
        self::assertNull(ProviderStatusMapper::mapShipping('complete-ish'));
    }

    public function testCircuitOpensAfterThresholdAndClosesAfterCooldown(): void
    {
        $circuit = new ProviderCircuitBreaker(2, 10);
        self::assertTrue($circuit->allows(100));
        $circuit->failure(100);
        self::assertTrue($circuit->allows(100));
        $circuit->failure(100);
        self::assertFalse($circuit->allows(109));
        self::assertTrue($circuit->allows(110));
        $circuit->success();
        self::assertTrue($circuit->allows(1));
    }

    public function testLocalFixturesCannotClassifyLiveCertificationAsPass(): void
    {
        $result = ProviderCertification::localFixtures();
        self::assertSame('LOCAL_PASS', $result['status']);
        self::assertSame('BLOCKED_EXTERNAL', $result['certification']);
    }
}
