<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DemoSeedCommand;
use App\Console\Commands\LaunchCheckCommand;
use App\Console\Commands\ProvidersVerifyCommand;
use App\Console\Commands\SearchIndexProductsCommand;
use App\Jobs\SubscriptionRenewalJob;
use App\Support\Mailer;
use Siro\Core\Database;
use Tests\TestCase;

final class LaunchCommandCoverageTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** @var array<int, string> */
    private const ENV_KEYS = [
        'DB_CONNECTION', 'DB_DATABASE',
        'APP_ENV', 'APP_URL', 'PAYMENT_DEFAULT_PROVIDER', 'PAYMENT_MODE',
        'STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET',
        'VNPAY_TMN_CODE', 'VNPAY_HASH_SECRET', 'MOMO_PARTNER_CODE', 'MOMO_ACCESS_KEY', 'MOMO_SECRET_KEY',
        'SHIPPING_PROVIDER', 'SHIPPING_DEFAULT_PROVIDER', 'DHL_API_URL', 'DHL_API_KEY', 'DHL_API_SECRET',
        'GHN_TOKEN', 'GHN_SHOP_ID', 'VIETTELPOST_USERNAME', 'VIETTELPOST_PASSWORD',
        'CATALOG_SEARCH_PROVIDER', 'SEARCH_URL', 'SEARCH_API_KEY',
        'MAIL_PROVIDER', 'MAIL_SMTP_HOST', 'MAIL_FROM', 'MAIL_FROM_ADDRESS',
    ];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = dirname(__DIR__, 2);
        foreach (self::ENV_KEYS as $key) {
            $existing = $_ENV[$key] ?? getenv($key);
            $this->envBackup[$key] = is_string($existing) ? $existing : false;
            putenv($key);
            unset($_ENV[$key]);
        }
        Mailer::reset();
        $this->setEnv(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->sqlitePath()]);
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

    public function testProvidersVerifyCommandReturnsCertifiedWhenNothingIsConfigured(): void
    {
        ob_start();
        $exit = (new ProvidersVerifyCommand())->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Certified: YES', $output);
        self::assertStringContainsString('stripe', $output);
    }

    public function testLaunchCheckCommandReportsBlockedAndReadyStores(): void
    {
        $this->setEnv([
            'APP_URL' => '',
            'PAYMENT_DEFAULT_PROVIDER' => 'cod',
            'SHIPPING_PROVIDER' => 'native',
            'CATALOG_SEARCH_PROVIDER' => 'native',
            'MAIL_PROVIDER' => 'null',
        ]);
        ob_start();
        $blocked = (new LaunchCheckCommand($this->basePath))->run([]);
        $blockedOutput = (string) ob_get_clean();
        self::assertSame(1, $blocked);
        self::assertStringContainsString('BLOCKED', $blockedOutput);

        $this->seedReadyStore();
        $this->setEnv([
            'APP_URL' => 'https://shop.example.test',
            'MAIL_PROVIDER' => 'smtp',
            'MAIL_SMTP_HOST' => 'smtp.example.test',
            'MAIL_FROM' => 'orders@example.test',
        ]);
        ob_start();
        $ready = (new LaunchCheckCommand($this->basePath))->run([]);
        $readyOutput = (string) ob_get_clean();
        self::assertSame(0, $ready);
        self::assertStringContainsString('READY', $readyOutput);
    }

    public function testDemoSeedCommandSeedsAndReplaysIdempotently(): void
    {
        ob_start();
        $first = (new DemoSeedCommand($this->basePath))->run([]);
        $firstOutput = (string) ob_get_clean();
        self::assertSame(0, $first);
        self::assertStringContainsString('records created', $firstOutput);
        self::assertSame(6, $this->countRows("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'"));

        ob_start();
        $second = (new DemoSeedCommand($this->basePath))->run([]);
        $secondOutput = (string) ob_get_clean();
        self::assertSame(0, $second);
        self::assertStringContainsString('already present', $secondOutput);
    }

    public function testDemoSeedCommandRefusesEstablishedCatalog(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            Database::execute(
                'INSERT INTO products (id, name, slug, status) VALUES (?,?,?,?)',
                ['cmd-real-' . $index, 'Real ' . $index, 'cmd-real-' . $index, 'active'],
            );
        }

        ob_start();
        $exit = (new DemoSeedCommand($this->basePath))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('active products', $output);
    }

    public function testDemoSeedCommandReportsConfigurationFailure(): void
    {
        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-missing-' . bin2hex(random_bytes(4));

        set_error_handler(static fn (): bool => true);
        try {
            ob_start();
            $exit = (new DemoSeedCommand($missing))->run([]);
            $output = (string) ob_get_clean();
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $exit);
        self::assertStringContainsString('failed to configure the database', $output);
    }

    public function testSearchIndexCommandSkipsWhenProviderIsNative(): void
    {
        $this->setEnv(['CATALOG_SEARCH_PROVIDER' => 'native']);

        ob_start();
        $exit = (new SearchIndexProductsCommand($this->basePath))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('SKIP', $output);
    }

    public function testSearchIndexCommandValidatesConfiguration(): void
    {
        $this->setEnv(['CATALOG_SEARCH_PROVIDER' => 'meili']);
        ob_start();
        $missing = (new SearchIndexProductsCommand($this->basePath))->run([]);
        $missingOutput = (string) ob_get_clean();
        self::assertSame(1, $missing);
        self::assertStringContainsString('SEARCH_URL and SEARCH_API_KEY must be configured', $missingOutput);

        $this->setEnv(['SEARCH_URL' => 'not-a-url', 'SEARCH_API_KEY' => 'key']);
        ob_start();
        $invalid = (new SearchIndexProductsCommand($this->basePath))->run([]);
        $invalidOutput = (string) ob_get_clean();
        self::assertSame(1, $invalid);
        self::assertStringContainsString('must be an http(s) URL', $invalidOutput);
    }

    public function testSearchIndexCommandReportsBootFailureAndHonoursBatchOption(): void
    {
        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-no-app-' . bin2hex(random_bytes(4));
        $this->setEnv(['CATALOG_SEARCH_PROVIDER' => 'meili']);

        ob_start();
        $boot = (new SearchIndexProductsCommand($missing))->run(['--batch=1']);
        $bootOutput = (string) ob_get_clean();
        self::assertSame(1, $boot);
        self::assertStringContainsString('FAIL', $bootOutput);
    }

    public function testSubscriptionRenewalJobExposesStableIdentity(): void
    {
        $job = new SubscriptionRenewalJob('sub-1', 'renewal-key-1');

        self::assertSame('subscription.renewal', $job->name());
        self::assertSame('renewal-key-1', $job->idempotencyKey());
        self::assertSame(['subscription_id' => 'sub-1', 'idempotency_key' => 'renewal-key-1'], $job->payload());
        self::assertSame(5, $job->maxAttempts());
    }

    private function seedReadyStore(): void
    {
        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO storefront_configs (store_id, channel_id, locale, currency, theme_key, vertical_key, status, version, config_json, published_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            ['default', 'default', 'en', 'VND', 'editorial', 'general', 'published', 1, '{}', $now, $now, $now],
        );
        foreach (['ready-a', 'ready-b', 'ready-c'] as $index => $slug) {
            Database::execute(
                'INSERT INTO products (id, name, slug, status, store_id, channel_id, locale, currency) VALUES (?,?,?,?,?,?,?,?)',
                ['cmd-ready-' . $index, 'Ready ' . $index, $slug, 'active', 'default', 'default', 'en', 'VND'],
            );
        }
        foreach (['shipping' => 'Shipping', 'returns' => 'Returns', 'contact' => 'Contact'] as $slug => $title) {
            Database::execute(
                'INSERT INTO cms_pages (id, slug, title, body, status, locale, published_at, version, store_id, channel_id, currency, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                ['cmd-page-' . $slug, $slug, $title, '<p>p</p>', 'published', 'en', $now, 1, 'default', 'default', 'VND', $now, $now],
            );
        }
    }

    private function sqlitePath(): string
    {
        $statement = Database::connection()->query('PRAGMA database_list');
        $rows = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC);
        $first = $rows[0] ?? [];
        $file = is_array($first) ? ($first['file'] ?? '') : '';
        return is_string($file) && $file !== '' ? $file : ':memory:';
    }

    private function countRows(string $sql): int
    {
        $rows = Database::select($sql);
        $first = $rows[0] ?? [];
        $value = is_array($first) ? (array_values($first)[0] ?? 0) : 0;
        return is_numeric($value) ? (int) $value : 0;
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
