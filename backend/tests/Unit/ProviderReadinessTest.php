<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProviderReadiness;
use PHPUnit\Framework\TestCase;

final class ProviderReadinessTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_ENV', 'PAYMENT_MODE', 'PAYMENT_DEFAULT_PROVIDER', 'PAYPAL_ACCESS_TOKEN', 'PAYPAL_WEBHOOK_SECRET', 'SHIPPING_DEFAULT_PROVIDER', 'DHL_MODE', 'DHL_API_URL', 'DHL_API_KEY', 'DHL_API_SECRET', 'DHL_WEBHOOK_SECRET', 'TAX_PROVIDER'] as $name) {
            $this->environment[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
        parent::tearDown();
    }

    public function testProductionReadinessFailsWithoutSecrets(): void
    {
        putenv('APP_ENV=production');
        $result = ProviderReadiness::check();
        self::assertFalse($result['ready']);
        self::assertContains('STRIPE_SECRET_KEY', $result['missing']);
        self::assertArrayHasKey('ready', $result);
        self::assertArrayHasKey('missing', $result);
        self::assertArrayNotHasKey('value', $result);
    }

    public function testProductionReadinessUsesRuntimeCredentialNames(): void
    {
        putenv('APP_ENV=production');
        putenv('PAYMENT_MODE=production');
        putenv('PAYMENT_DEFAULT_PROVIDER=paypal');
        putenv('PAYPAL_ACCESS_TOKEN=access-token');
        putenv('PAYPAL_WEBHOOK_SECRET=webhook-secret');
        putenv('DHL_MODE=api');
        putenv('DHL_API_URL=https://dhl.example.test');
        putenv('DHL_API_KEY=dhl-key');
        putenv('DHL_API_SECRET=dhl-secret');
        putenv('DHL_WEBHOOK_SECRET=dhl-webhook');

        $result = ProviderReadiness::check();

        self::assertTrue($result['ready']);
        self::assertNotContains('PAYPAL_CLIENT_ID', $result['missing']);
        self::assertNotContains('ADYEN_MERCHANT_ACCOUNT', $result['missing']);
    }

    public function testInvalidProviderConfigurationFailsClosed(): void
    {
        putenv('APP_ENV=local');
        putenv('PAYMENT_DEFAULT_PROVIDER=unsupported');
        putenv('PAYMENT_MODE=invalid');

        $result = ProviderReadiness::check();

        self::assertFalse($result['ready']);
        self::assertContains('PAYMENT_DEFAULT_PROVIDER', $result['missing']);
        self::assertContains('PAYMENT_MODE=sandbox|production', $result['missing']);
    }

    public function testUnsupportedShippingProviderFailsClosedAgainstRuntimeRegistry(): void
    {
        putenv('APP_ENV=local');
        putenv('SHIPPING_DEFAULT_PROVIDER=ups');

        $result = ProviderReadiness::check();

        self::assertFalse($result['ready']);
        self::assertContains('SHIPPING_DEFAULT_PROVIDER=dhl', $result['missing']);
        self::assertSame('ups', $result['shipping']['provider']);
    }

    public function testUnsupportedTaxProviderFailsClosed(): void
    {
        putenv('APP_ENV=local');
        putenv('TAX_PROVIDER=external');

        $result = ProviderReadiness::check();

        self::assertFalse($result['ready']);
        self::assertContains('TAX_PROVIDER=native', $result['missing']);
        self::assertSame('external', $result['tax']['provider']);
    }

    public function testTransactionalEmailReadinessIsVisibleWithoutLeakingSecrets(): void
    {
        foreach (['MAIL_SMTP_HOST', 'MAIL_FROM'] as $name) { putenv($name); unset($_ENV[$name]); }
        try {
            $result = ProviderReadiness::check();
            self::assertFalse($result['transactional_email']['configured']);
            putenv('MAIL_SMTP_HOST=smtp.example.test');
            $_ENV['MAIL_SMTP_HOST'] = 'smtp.example.test';
            putenv('MAIL_FROM=shop@example.test');
            $_ENV['MAIL_FROM'] = 'shop@example.test';
            $result = ProviderReadiness::check();
            self::assertTrue($result['transactional_email']['configured']);
            self::assertStringNotContainsString('smtp.example.test', (string) json_encode($result));
            self::assertStringNotContainsString('shop@example.test', (string) json_encode($result));
        } finally {
            foreach (['MAIL_SMTP_HOST', 'MAIL_FROM'] as $name) { putenv($name); unset($_ENV[$name]); }
        }
    }
}
