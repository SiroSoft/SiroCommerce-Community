<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PaymentLaunchCertification;
use PHPUnit\Framework\TestCase;

final class PaymentLaunchCertificationTest extends TestCase
{
    public function testSandboxIsUsefulButCannotClaimLiveCertification(): void
    {
        $report = PaymentLaunchCertification::run(['PAYMENT_DEFAULT_PROVIDER' => 'stripe', 'PAYMENT_MODE' => 'sandbox', 'PAYMENT_CERTIFICATION_SANDBOX' => '1']);
        self::assertSame('BLOCKED_EXTERNAL', $report['status']);
        self::assertSame('NOT_CERTIFIED', $report['live_claim']);
        self::assertSame('PASS', $report['sandbox']['status']);
        self::assertSame('PASS', $report['sandbox']['checks']['capture_refund_money']);
        self::assertSame('PASS', $report['sandbox']['checks']['webhook_signature_and_tamper']);
        self::assertSame('PASS', $report['sandbox']['checks']['reconciliation_unknown_is_explicit']);
        self::assertArrayNotHasKey('secret_key', $report);
    }

    public function testProductionReportsExactMissingCredentialGroups(): void
    {
        $report = PaymentLaunchCertification::run(['PAYMENT_DEFAULT_PROVIDER' => 'paypal', 'PAYMENT_MODE' => 'production', 'PAYMENT_CERTIFICATION_LIVE' => '0']);
        self::assertSame('BLOCKED_EXTERNAL', $report['status']);
        self::assertSame(['PAYPAL_ACCESS_TOKEN|PAYMENT_PAYPAL_ACCESS_TOKEN', 'PAYPAL_WEBHOOK_SECRET|PAYMENT_PAYPAL_WEBHOOK_SECRET'], $report['checks'][1]['evidence']);
        self::assertSame('NOT_CERTIFIED', $report['live_claim']);
    }
}
