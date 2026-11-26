<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\EvidenceStatus;
use PHPUnit\Framework\TestCase;

final class EvidenceStatusTest extends TestCase
{
    public function testExternalBlockerIsActionableAndNeverPass(): void
    {
        $result = EvidenceStatus::blockedExternal('credentials missing', 'Provider Operations', 'STRIPE_SECRET_KEY', 'php scripts/provider-preflight.php');

        self::assertSame('BLOCKED_EXTERNAL', $result['status']);
        self::assertSame('Provider Operations', $result['owner']);
        self::assertSame('STRIPE_SECRET_KEY', $result['prerequisite']);
        self::assertSame('php scripts/provider-preflight.php', $result['command']);
        self::assertNotSame('PASS', $result['status']);
    }
}
