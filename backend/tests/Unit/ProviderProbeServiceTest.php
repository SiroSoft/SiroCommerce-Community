<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Launch\Application\ProviderProbeService;
use App\Support\Mailer;
use PHPUnit\Framework\TestCase;

final class ProviderProbeServiceTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, timeoutMs: int}> */
    private array $calls = [];

    private const KEYS = [
        'STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET',
        'VNPAY_TMN_CODE', 'VNPAY_HASH_SECRET', 'MOMO_PARTNER_CODE', 'MOMO_ACCESS_KEY', 'MOMO_SECRET_KEY',
        'GHN_ENV', 'GHN_API_URL', 'GHN_TOKEN', 'GHN_SHOP_ID',
        'VIETTELPOST_API_URL', 'VIETTELPOST_USERNAME', 'VIETTELPOST_PASSWORD',
        'DHL_API_URL', 'DHL_API_KEY', 'DHL_API_SECRET', 'DHL_MODE',
        'CATALOG_SEARCH_PROVIDER', 'SEARCH_URL', 'SEARCH_API_KEY',
        'MAIL_PROVIDER', 'MAIL_SMTP_HOST', 'MAIL_FROM', 'MAIL_HOST', 'MAIL_FROM_ADDRESS',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) {
            $this->env[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
        Mailer::reset();
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        Mailer::reset();
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

    public function testReachableConfiguredProvidersAreCertified(): void
    {
        $this->setEnv([
            'STRIPE_SECRET_KEY' => 'sk_test_secret_value',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_secret_value',
            'GHN_TOKEN' => 'ghn-token-value',
            'GHN_SHOP_ID' => 'shop-123',
            'CATALOG_SEARCH_PROVIDER' => 'meili',
            'SEARCH_URL' => 'https://search.internal',
            'SEARCH_API_KEY' => 'meili-secret-value',
            'MAIL_SMTP_HOST' => 'smtp.internal',
            'MAIL_FROM' => 'ops@shop.test',
            'VNPAY_TMN_CODE' => 'tmn-value',
            'VNPAY_HASH_SECRET' => 'vnpay-secret-value',
            'MOMO_PARTNER_CODE' => 'MOMO',
            'MOMO_ACCESS_KEY' => 'momo-access-value',
            'MOMO_SECRET_KEY' => 'momo-secret-value',
            'VIETTELPOST_USERNAME' => 'viettel-user',
            'VIETTELPOST_PASSWORD' => 'viettel-secret-value',
            'DHL_API_URL' => 'https://dhl.example.test',
            'DHL_API_KEY' => 'dhl-key-value',
            'DHL_API_SECRET' => 'dhl-secret-value',
        ]);

        $result = (new ProviderProbeService(null, $this->httpOk()))->verify();

        self::assertTrue($result['certified']);
        $byName = [];
        foreach ($result['providers'] as $provider) {
            self::assertSame(['name', 'type', 'configured', 'reachable', 'detail'], array_keys($provider));
            $byName[$provider['name']] = $provider;
        }
        foreach (['stripe', 'vnpay', 'momo', 'ghn', 'viettelpost', 'dhl', 'meili', 'mail'] as $name) {
            self::assertArrayHasKey($name, $byName);
            self::assertTrue($byName[$name]['configured'], $name . ' should be configured');
            self::assertTrue($byName[$name]['reachable'], $name . ' should be reachable');
        }
        self::assertStringContainsString('balance HTTP 200', $byName['stripe']['detail']);
        self::assertStringContainsString('shop/all HTTP 200', $byName['ghn']['detail']);
        self::assertStringContainsString('health HTTP 200', $byName['meili']['detail']);
        self::assertSame('configuration only (no read API)', $byName['vnpay']['detail']);

        $urls = array_column($this->calls, 'url');
        self::assertContains('https://api.stripe.com/v1/balance', $urls);
        self::assertContains('https://dev-online-gateway.ghn.vn/shiip/public-api/v2/shop/all', $urls);
        self::assertContains('https://search.internal/health', $urls);
        self::assertContains('https://search.internal/stats', $urls);
        foreach ($this->calls as $call) {
            self::assertSame(ProviderProbeService::TIMEOUT_MS, $call['timeoutMs']);
        }
    }

    public function testUnreachableConfiguredProviderBreaksCertification(): void
    {
        $this->setEnv([
            'STRIPE_SECRET_KEY' => 'sk_test_secret_value',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_secret_value',
        ]);

        $result = (new ProviderProbeService(null, $this->httpStatus(401)))->verify();

        self::assertFalse($result['certified']);
        $stripe = $this->find($result['providers'], 'stripe');
        self::assertNotNull($stripe);
        self::assertTrue($stripe['configured']);
        self::assertFalse($stripe['reachable']);
        self::assertSame('balance HTTP 401', $stripe['detail']);
    }

    public function testMissingCredentialsAreNotFailures(): void
    {
        $result = (new ProviderProbeService(null, $this->httpOk()))->verify();

        self::assertTrue($result['certified']);
        foreach ($result['providers'] as $provider) {
            self::assertFalse($provider['configured']);
            self::assertFalse($provider['reachable']);
            self::assertSame('credentials missing', $provider['detail']);
        }
        self::assertSame([], $this->calls);
    }

    public function testTransportFailuresAreReportedWithoutSecrets(): void
    {
        $this->setEnv([
            'STRIPE_SECRET_KEY' => 'sk_live_do_not_leak',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_do_not_leak',
            'GHN_TOKEN' => 'ghn_do_not_leak',
            'GHN_SHOP_ID' => 'shop-do-not-leak',
        ]);

        $result = (new ProviderProbeService(null, static function (): array {
            throw new \RuntimeException('network failure with sk_live_do_not_leak');
        }))->verify();

        self::assertFalse($result['certified']);
        self::assertSame('balance request failed', $this->find($result['providers'], 'stripe')['detail'] ?? null);
        self::assertSame('shop/all request failed', $this->find($result['providers'], 'ghn')['detail'] ?? null);
        $encoded = (string) json_encode($result);
        self::assertStringNotContainsString('sk_live_do_not_leak', $encoded);
        self::assertStringNotContainsString('whsec_do_not_leak', $encoded);
        self::assertStringNotContainsString('ghn_do_not_leak', $encoded);
        self::assertStringNotContainsString('shop-do-not-leak', $encoded);
    }

    /** @param array<int, array{name: string, type: string, configured: bool, reachable: bool, detail: string}> $providers */
    private function find(array $providers, string $name): ?array
    {
        foreach ($providers as $provider) {
            if ($provider['name'] === $name) {
                return $provider;
            }
        }
        return null;
    }

    /** @return \Closure(string, string, array<string, string>, int): array{status: int, error?: string} */
    private function httpOk(): \Closure
    {
        return function (string $method, string $url, array $headers, int $timeoutMs): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'timeoutMs' => $timeoutMs];
            return ['status' => 200];
        };
    }

    /** @return \Closure(string, string, array<string, string>, int): array{status: int, error?: string} */
    private function httpStatus(int $status): \Closure
    {
        return function (string $method, string $url, array $headers, int $timeoutMs) use ($status): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'timeoutMs' => $timeoutMs];
            return ['status' => $status];
        };
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
