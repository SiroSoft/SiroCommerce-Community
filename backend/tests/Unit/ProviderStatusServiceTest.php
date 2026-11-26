<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Launch\Application\ProviderStatusService;
use PHPUnit\Framework\TestCase;

final class ProviderStatusServiceTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'PAYMENT_DEFAULT_PROVIDER', 'PAYMENT_MODE', 'VNPAY_TMN_CODE', 'VNPAY_HASH_SECRET',
            'MOMO_PARTNER_CODE', 'MOMO_ACCESS_KEY', 'MOMO_SECRET_KEY', 'MOMO_API_URL',
            'SHIPPING_PROVIDER', 'SHIPPING_DEFAULT_PROVIDER', 'GHN_ENV', 'GHN_TOKEN', 'GHN_SHOP_ID',
            'VIETTELPOST_API_URL', 'VIETTELPOST_USERNAME', 'VIETTELPOST_PASSWORD', 'DHL_MODE',
        ] as $key) {
            $this->env[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testVnProvidersAreExposedAsNamesAndBooleansOnly(): void
    {
        $this->setEnv([
            'PAYMENT_DEFAULT_PROVIDER' => 'vnpay',
            'PAYMENT_MODE' => 'sandbox',
            'VNPAY_TMN_CODE' => 'tmn-live-123',
            'VNPAY_HASH_SECRET' => 'hash-secret-xyz',
            'SHIPPING_PROVIDER' => 'ghn',
            'GHN_ENV' => 'sandbox',
            'GHN_TOKEN' => 'ghn-token-xyz',
            'GHN_SHOP_ID' => 'shop-123',
        ]);

        $status = (new ProviderStatusService())->status();
        self::assertSame('vnpay', $status['payment']['provider']);
        self::assertTrue($status['payment']['configured']);
        self::assertSame('ghn', $status['shipping']['provider']);
        self::assertSame('sandbox', $status['shipping']['mode']);
        self::assertTrue($status['shipping']['configured']);
        $encoded = (string) json_encode($status);
        self::assertStringNotContainsString('tmn-live-123', $encoded);
        self::assertStringNotContainsString('hash-secret-xyz', $encoded);
        self::assertStringNotContainsString('ghn-token-xyz', $encoded);
    }

    public function testMomoAndViettelPostStatusAreConfigurationOnly(): void
    {
        $this->setEnv([
            'PAYMENT_DEFAULT_PROVIDER' => 'momo',
            'PAYMENT_MODE' => 'production',
            'MOMO_PARTNER_CODE' => 'MOMO',
            'MOMO_ACCESS_KEY' => 'ACCESS',
            'MOMO_SECRET_KEY' => 'SECRET',
            'SHIPPING_PROVIDER' => 'viettelpost',
            'VIETTELPOST_API_URL' => 'https://sandbox.viettelpost.vn',
            'VIETTELPOST_USERNAME' => 'partner-user',
            'VIETTELPOST_PASSWORD' => 'partner-pass',
        ]);

        $status = (new ProviderStatusService())->status();
        self::assertSame('momo', $status['payment']['provider']);
        self::assertTrue($status['payment']['configured']);
        self::assertSame('viettelpost', $status['shipping']['provider']);
        self::assertSame('sandbox', $status['shipping']['mode']);
        self::assertTrue($status['shipping']['configured']);
        self::assertSame(['provider', 'mode', 'configured'], array_keys($status['payment']));
        self::assertSame(['provider', 'mode', 'configured'], array_keys($status['shipping']));
    }

    public function testMissingVnCredentialsReportNotConfigured(): void
    {
        $this->setEnv([
            'PAYMENT_DEFAULT_PROVIDER' => 'momo',
            'PAYMENT_MODE' => 'sandbox',
            'MOMO_PARTNER_CODE' => '',
            'MOMO_ACCESS_KEY' => '',
            'MOMO_SECRET_KEY' => '',
            'SHIPPING_PROVIDER' => 'ghn',
            'GHN_TOKEN' => '',
            'GHN_SHOP_ID' => '',
        ]);

        $status = (new ProviderStatusService())->status();
        self::assertFalse($status['payment']['configured']);
        self::assertFalse($status['shipping']['configured']);
    }

    /** @param array<string, string> $values */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
