<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Launch\Application\LaunchStatusService;
use App\Modules\Launch\Application\ProviderProbeService;
use App\Support\Mailer;
use Siro\Core\Database;
use Tests\TestCase;

final class LaunchAndProviderBranchesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    private const ENV_KEYS = [
        'APP_ENV', 'APP_URL', 'PAYMENT_DEFAULT_PROVIDER', 'PAYMENT_MODE',
        'STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET',
        'SHIPPING_PROVIDER', 'SHIPPING_DEFAULT_PROVIDER', 'DHL_API_URL', 'DHL_API_KEY', 'DHL_API_SECRET', 'DHL_MODE',
        'GHN_TOKEN', 'GHN_SHOP_ID', 'GHN_ENV', 'GHN_API_URL',
        'CATALOG_SEARCH_PROVIDER', 'SEARCH_URL', 'SEARCH_API_KEY',
        'MAIL_PROVIDER', 'MAIL_SMTP_HOST', 'MAIL_FROM', 'MAIL_FROM_ADDRESS', 'MAIL_HOST',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $key) {
            $existing = $_ENV[$key] ?? getenv($key);
            $this->envBackup[$key] = is_string($existing) ? $existing : false;
            putenv($key);
            unset($_ENV[$key]);
        }
        Mailer::reset();
    }

    protected function tearDown(): void
    {
        Mailer::reset();
        foreach ($this->envBackup as $key => $value) {
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

    public function testPartialStoreStatesReportWarnings(): void
    {
        $this->setEnv([
            'APP_URL' => 'http://shop.example.test',
            'PAYMENT_DEFAULT_PROVIDER' => 'cod',
            'SHIPPING_PROVIDER' => 'native',
            'CATALOG_SEARCH_PROVIDER' => 'native',
            'MAIL_PROVIDER' => 'null',
        ]);
        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO storefront_configs (store_id, channel_id, locale, currency, theme_key, vertical_key, status, version, config_json, published_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            ['default', 'default', 'en', 'VND', 'editorial', 'general', 'draft', 1, '{}', null, $now, $now],
        );
        Database::execute(
            'INSERT INTO products (id, name, slug, status, store_id, channel_id, locale, currency) VALUES (?,?,?,?,?,?,?,?)',
            ['partial-1', 'Partial', 'partial-1', 'active', 'default', 'default', 'en', 'VND'],
        );
        Database::execute(
            'INSERT INTO cms_pages (id, slug, title, body, status, locale, published_at, version, store_id, channel_id, currency, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            ['page-shipping-only', 'shipping', 'Shipping', '<p>p</p>', 'published', 'en', $now, 1, 'default', 'default', 'VND', $now, $now],
        );

        $status = (new LaunchStatusService())->status();
        $checks = $this->checksByKey($status['checks']);

        self::assertFalse($status['ready']);
        self::assertSame('warn', $checks['store_config']['state']);
        self::assertSame('warn', $checks['catalog']['state']);
        self::assertSame('warn', $checks['legal_pages']['state']);
        self::assertSame('warn', $checks['domain_tls']['state']);
        self::assertSame('ok', $checks['payments']['state']);
        self::assertSame('ok', $checks['shipping']['state']);
        self::assertStringContainsString('config exists but is not published', $checks['store_config']['detail']);
    }

    public function testInvalidProvidersAndMissingCredentialBranches(): void
    {
        $this->setEnv([
            'APP_ENV' => 'production',
            'APP_URL' => 'https://shop.example.test',
            'PAYMENT_DEFAULT_PROVIDER' => 'bogus',
            'SHIPPING_PROVIDER' => 'ups',
            'CATALOG_SEARCH_PROVIDER' => 'meili',
            'MAIL_PROVIDER' => 'null',
        ]);
        $checks = $this->checksByKey((new LaunchStatusService())->status()['checks']);
        self::assertSame('fail', $checks['payments']['state']);
        self::assertStringContainsString('provider: bogus', $checks['payments']['detail']);
        self::assertSame('fail', $checks['shipping']['state']);
        self::assertSame('warn', $checks['search']['state']);

        $this->setEnv([
            'PAYMENT_DEFAULT_PROVIDER' => 'stripe',
            'STRIPE_SECRET_KEY' => '',
            'STRIPE_WEBHOOK_SECRET' => '',
            'SHIPPING_PROVIDER' => 'dhl',
            'DHL_API_URL' => '',
            'DHL_API_KEY' => '',
            'DHL_API_SECRET' => '',
        ]);
        $checks = $this->checksByKey((new LaunchStatusService())->status()['checks']);
        self::assertSame('fail', $checks['payments']['state']);
        self::assertStringContainsString('credentials: missing', $checks['payments']['detail']);
        self::assertSame('warn', $checks['shipping']['state']);
        self::assertStringContainsString('credentials: missing', $checks['shipping']['detail']);

        $this->setEnv([
            'APP_ENV' => 'testing',
            'STRIPE_SECRET_KEY' => 'sk_test_value',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_value',
            'PAYMENT_MODE' => 'sandbox',
            'DHL_API_URL' => 'https://dhl.example.test',
            'DHL_API_KEY' => 'key',
            'DHL_API_SECRET' => 'secret',
            'DHL_MODE' => 'sandbox',
        ]);
        $checks = $this->checksByKey((new LaunchStatusService())->status()['checks']);
        self::assertSame('warn', $checks['payments']['state']);
        self::assertSame('warn', $checks['shipping']['state']);
        self::assertStringContainsString('mode: sandbox', $checks['payments']['detail']);

        $this->setEnv([
            'APP_ENV' => 'production',
            'PAYMENT_MODE' => 'production',
            'DHL_MODE' => 'api',
        ]);
        $checks = $this->checksByKey((new LaunchStatusService())->status()['checks']);
        self::assertSame('ok', $checks['payments']['state']);
        self::assertSame('ok', $checks['shipping']['state']);

        $this->setEnv(['PAYMENT_MODE' => 'staging']);
        $checks = $this->checksByKey((new LaunchStatusService())->status()['checks']);
        self::assertSame('fail', $checks['payments']['state']);
        self::assertStringContainsString('mode invalid', $checks['payments']['detail']);
    }

    public function testInvalidAppUrlAndMeiliWithoutReachableIndex(): void
    {
        $this->setEnv([
            'APP_URL' => 'not-a-url',
            'PAYMENT_DEFAULT_PROVIDER' => 'cod',
            'SHIPPING_PROVIDER' => 'native',
            'CATALOG_SEARCH_PROVIDER' => 'meili',
            'SEARCH_URL' => 'not-a-url',
            'SEARCH_API_KEY' => 'search-key',
            'MAIL_PROVIDER' => 'null',
        ]);
        $checks = $this->checksByKey((new LaunchStatusService())->status()['checks']);
        self::assertSame('fail', $checks['domain_tls']['state']);
        self::assertStringContainsString('APP_URL: invalid', $checks['domain_tls']['detail']);
        self::assertSame('warn', $checks['search']['state']);
    }

    public function testProviderProbeUsesFallbackCredentialsAndReportsProbeFailures(): void
    {
        $this->setEnv([
            'PAYMENT_STRIPE_SECRET_KEY' => 'sk_fallback_value',
            'PAYMENT_STRIPE_WEBHOOK_SECRET' => 'whsec_fallback_value',
            'GHN_TOKEN' => 'ghn-token',
            'GHN_SHOP_ID' => 'shop-id',
            'GHN_ENV' => 'production',
            'CATALOG_SEARCH_PROVIDER' => 'meili',
            'SEARCH_URL' => 'https://search.example.test',
            'SEARCH_API_KEY' => 'search-key',
            'MAIL_SMTP_HOST' => 'smtp.example.test',
            'MAIL_FROM' => 'ops@example.test',
        ]);

        $calls = [];
        $transport = function (string $method, string $url, array $headers, int $timeout) use (&$calls): array {
            $calls[] = ['url' => $url, 'headers' => $headers];
            if (str_ends_with($url, '/stats')) {
                return ['status' => 503, 'error' => null];
            }
            return ['status' => 200, 'error' => null];
        };

        $result = (new ProviderProbeService(null, $transport))->verify();
        $byName = $this->providersByName($result['providers']);

        self::assertTrue($byName['stripe']['reachable']);
        self::assertSame('balance HTTP 200', $byName['stripe']['detail']);
        self::assertTrue($byName['ghn']['reachable']);
        self::assertStringContainsString('online-gateway.ghn.vn', implode(',', array_column($calls, 'url')));
        self::assertTrue($byName['meili']['configured']);
        self::assertFalse($byName['meili']['reachable']);
        self::assertSame('stats HTTP 503', $byName['meili']['detail']);
        self::assertTrue($byName['mail']['reachable']);
        self::assertFalse($result['certified']);
    }

    public function testProviderProbeReportsHealthTransportErrorWithoutLeakingHeaders(): void
    {
        $this->setEnv([
            'CATALOG_SEARCH_PROVIDER' => 'meilisearch',
            'SEARCH_URL' => 'https://search.example.test',
            'SEARCH_API_KEY' => 'secret-search-key',
        ]);

        $transport = static function (string $method, string $url, array $headers, int $timeout): array {
            return ['status' => 0, 'error' => 'connection reset'];
        };

        $result = (new ProviderProbeService(null, $transport))->verify();
        $meili = $this->providersByName($result['providers'])['meili'];

        self::assertTrue($meili['configured']);
        self::assertFalse($meili['reachable']);
        self::assertSame('health request failed', $meili['detail']);
        self::assertStringNotContainsString('secret-search-key', (string) json_encode($result));
    }

    /** @param array<int, array<string, mixed>> $checks @return array<string, array<string, mixed>> */
    private function checksByKey(array $checks): array
    {
        $indexed = [];
        foreach ($checks as $check) {
            $indexed[(string) $check['key']] = $check;
        }
        return $indexed;
    }

    /** @param array<int, array<string, mixed>> $providers @return array<string, array<string, mixed>> */
    private function providersByName(array $providers): array
    {
        $indexed = [];
        foreach ($providers as $provider) {
            $indexed[(string) $provider['name']] = $provider;
        }
        return $indexed;
    }

    /** @param array<string, string> $values */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
