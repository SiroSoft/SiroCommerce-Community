<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Launch\Application\LaunchStatusService;
use Siro\Core\Database;
use Tests\TestCase;

final class LaunchStatusServiceTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }
        $this->envBackup = [];
        parent::tearDown();
    }

    public function testFreshStoreReportsBlockingChecksAndWeightedScore(): void
    {
        $this->setEnv([
            'APP_ENV' => 'testing',
            'APP_URL' => '',
            'PAYMENT_DEFAULT_PROVIDER' => 'stripe',
            'PAYMENT_MODE' => 'sandbox',
            'STRIPE_SECRET_KEY' => '',
            'STRIPE_WEBHOOK_SECRET' => '',
            'SHIPPING_PROVIDER' => 'native',
            'CATALOG_SEARCH_PROVIDER' => 'native',
            'MAIL_PROVIDER' => 'null',
            'MAIL_SMTP_HOST' => '',
            'MAIL_FROM' => '',
            'MAIL_FROM_ADDRESS' => '',
        ]);

        $status = (new LaunchStatusService())->status();
        $checks = $this->checksByKey($status['checks']);

        self::assertFalse($status['ready']);
        self::assertSame('fail', $checks['store_config']['state']);
        self::assertSame('fail', $checks['catalog']['state']);
        self::assertSame('warn', $checks['payments']['state']);
        self::assertSame('ok', $checks['shipping']['state']);
        self::assertSame('warn', $checks['mail']['state']);
        self::assertSame('ok', $checks['search']['state']);
        self::assertSame('fail', $checks['legal_pages']['state']);
        self::assertSame('fail', $checks['domain_tls']['state']);
        self::assertSame(38, $status['score']);
        self::assertSame(['key', 'state', 'hint', 'href', 'weight', 'detail'], array_keys($status['checks'][0]));
    }

    public function testFullyConfiguredStoreScoresOneHundredAndWarnUsesHalfWeight(): void
    {
        $this->setEnv([
            'APP_ENV' => 'testing',
            'APP_URL' => 'https://shop.example.test',
            'PAYMENT_DEFAULT_PROVIDER' => 'cod',
            'PAYMENT_MODE' => 'production',
            'SHIPPING_PROVIDER' => 'native',
            'CATALOG_SEARCH_PROVIDER' => 'native',
            'MAIL_PROVIDER' => 'smtp',
            'MAIL_SMTP_HOST' => 'smtp.example.test',
            'MAIL_FROM' => 'orders@example.test',
        ]);

        $this->seedPublishedStore();

        $status = (new LaunchStatusService())->status();
        self::assertTrue($status['ready']);
        self::assertSame(100, $status['score']);

        $this->setEnv(['APP_URL' => 'http://shop.example.test']);
        $status = (new LaunchStatusService())->status();
        $checks = $this->checksByKey($status['checks']);
        self::assertFalse($status['ready']);
        self::assertSame('warn', $checks['domain_tls']['state']);
        self::assertSame(94, $status['score']);
    }

    private function seedPublishedStore(): void
    {
        $now = date('Y-m-d H:i:s');
        Database::execute(
            "INSERT INTO storefront_configs (store_id, channel_id, locale, currency, theme_key, vertical_key, status, version, config_json, published_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            ['default', 'default', 'en', 'VND', 'editorial', 'general', 'published', 1, '{}', $now, $now, $now],
        );
        foreach (['launch-a', 'launch-b', 'launch-c'] as $index => $slug) {
            Database::execute(
                'INSERT INTO products (id, name, slug, status, store_id, channel_id, locale, currency) VALUES (?,?,?,?,?,?,?,?)',
                ['prod-' . $index, 'Launch ' . $index, $slug, 'active', 'default', 'default', 'en', 'VND'],
            );
        }
        foreach (['shipping' => 'Shipping', 'returns' => 'Returns', 'contact' => 'Contact'] as $slug => $title) {
            Database::execute(
                'INSERT INTO cms_pages (id, slug, title, body, status, locale, published_at, version, store_id, channel_id, currency, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                ['page-' . $slug, $slug, $title, '<p>Policy</p>', 'published', 'en', $now, 1, 'default', 'default', 'VND', $now, $now],
            );
        }
    }

    /** @param array<int, array<string, mixed>> $checks @return array<string, array<string, mixed>> */
    private function checksByKey(array $checks): array
    {
        $indexed = [];
        foreach ($checks as $check) {
            self::assertIsString($check['key']);
            $indexed[$check['key']] = $check;
        }
        return $indexed;
    }

    /** @param array<string, string> $values */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->envBackup)) {
                $existing = $_ENV[$key] ?? getenv($key);
                $this->envBackup[$key] = is_string($existing) ? $existing : false;
            }
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
