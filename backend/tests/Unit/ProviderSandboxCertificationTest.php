<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProviderSandboxCertification;
use PHPUnit\Framework\TestCase;

final class ProviderSandboxCertificationTest extends TestCase
{
    public function testAllLaunchScenariosAreDeterministicAndNotLiveClaims(): void
    {
        $result = ProviderSandboxCertification::run();
        self::assertSame('LOCAL_PASS', $result['status']);
        self::assertSame('BLOCKED_EXTERNAL', $result['certification']);
        self::assertSame(['quote', 'tax_evidence', 'label_tracking', 'delivery_update', 'order_notification', 'retry_dead_letter'], array_keys($result['scenarios']));
        foreach ($result['scenarios'] as $scenario) self::assertSame('LOCAL_PASS', $scenario['status']);
    }
}
