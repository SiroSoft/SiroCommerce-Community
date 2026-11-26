<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class StorefrontThemeLicenseGateTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
        'EXTENSION_LICENSE_STORE_FILE',
    ];

    /** @var array<string, string> */
    private array $originalEnv = [];

    private string $tempDir;

    /** @var \OpenSSLAsymmetricKey */
    private mixed $privateKey;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) $this->originalEnv[$key] = is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : '';
            $this->setEnv($key, null);
        }
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-theme-http-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for theme license tests');
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        $this->privateKey = $key;
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $details['key']);

        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $config = StorefrontConfigDefaults::config('default', 'web', 'en', 'VND');
        Database::table('storefront_configs')->insert([
            'id' => 1,
            'store_id' => 'default',
            'channel_id' => 'web',
            'locale' => 'en',
            'currency' => 'VND',
            'theme_key' => 'editorial',
            'vertical_key' => 'general',
            'status' => 'draft',
            'version' => 1,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->setEnv($key, $this->originalEnv[$key] ?? null);
        }
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testFreeThemeImportAndPublishAreUnaffected(): void
    {
        $headers = $this->adminHeaders();
        $import = $this->call('POST', '/api/admin/storefront/config/import-theme', ['theme' => $this->package('siro/pharmacy', 'pharmacy')], $headers + ['Idempotency-Key' => 'free-import']);
        self::assertSame(200, $import['status'], (string) json_encode($import['json']));
        self::assertSame('pharmacy', $import['json']['data']['config']['theme']['key']);
        $version = (int) $import['json']['data']['version'];

        $publish = $this->call('POST', '/api/admin/storefront/config/publish', ['expected_version' => $version], $headers + ['Idempotency-Key' => 'free-publish']);
        self::assertSame(200, $publish['status'], (string) json_encode($publish['json']));
        self::assertSame('published', $publish['json']['data']['status']);
    }

    public function testLicensedThemeImportIsDeniedOnCommunityEditionWithoutDraftWrite(): void
    {
        $response = $this->call(
            'POST',
            '/api/admin/storefront/config/import-theme',
            ['theme' => $this->proPackage()],
            $this->adminHeaders() + ['Idempotency-Key' => 'pro-import-denied'],
        );

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('siro-theme-atelier-noir', (string) $response['json']['message']);
        self::assertSame(0, (int) Database::table('storefront_config_revisions')->count());
        self::assertSame(1, (int) $this->configRow()['version']);
    }

    public function testLicensedThemeImportIsDeniedWithExpiredLicense(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 5,
        ])));

        $response = $this->call(
            'POST',
            '/api/admin/storefront/config/import-theme',
            ['theme' => $this->proPackage()],
            $this->adminHeaders() + ['Idempotency-Key' => 'pro-import-expired'],
        );

        self::assertSame(422, $response['status']);
        self::assertStringContainsString('expired', (string) $response['json']['message']);
        self::assertSame(0, (int) Database::table('storefront_config_revisions')->count());
    }

    public function testLicensedThemeImportIsAllowedWithValidSkuAndThenPublishIsGated(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => ['siro-theme-atelier-noir']])));
        $headers = $this->adminHeaders();
        $import = $this->call(
            'POST',
            '/api/admin/storefront/config/import-theme',
            ['theme' => $this->proPackage()],
            $headers + ['Idempotency-Key' => 'pro-import-ok'],
        );
        self::assertSame(200, $import['status'], (string) json_encode($import['json']));
        self::assertSame('atelier-noir', $import['json']['data']['config']['theme']['key']);
        $version = (int) $import['json']['data']['version'];

        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['siro-theme-atelier-noir'],
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 5,
        ])));
        $denied = $this->call(
            'POST',
            '/api/admin/storefront/config/publish',
            ['expected_version' => $version],
            $headers + ['Idempotency-Key' => 'pro-publish-denied'],
        );
        self::assertSame(422, $denied['status']);
        self::assertStringContainsString('siro-theme-atelier-noir', (string) $denied['json']['message']);
        self::assertSame('draft', $this->configRow()['status']);
        self::assertSame($version, (int) $this->configRow()['version']);
    }

    public function testLicensedThemePublishIsAllowedWithValidSku(): void
    {
        $this->activate($this->signedLicense($this->payload(['extensions' => ['siro-theme-atelier-noir']])));
        $headers = $this->adminHeaders();
        $import = $this->call(
            'POST',
            '/api/admin/storefront/config/import-theme',
            ['theme' => $this->proPackage()],
            $headers + ['Idempotency-Key' => 'pro-import-publish-ok'],
        );
        self::assertSame(200, $import['status'], (string) json_encode($import['json']));
        $version = (int) $import['json']['data']['version'];

        $publish = $this->call(
            'POST',
            '/api/admin/storefront/config/publish',
            ['expected_version' => $version],
            $headers + ['Idempotency-Key' => 'pro-publish-ok'],
        );
        self::assertSame(200, $publish['status'], (string) json_encode($publish['json']));
        self::assertSame('published', $publish['json']['data']['status']);
    }

    /** @return array<string, mixed> */
    private function package(string $id, string $name, array $overrides = []): array
    {
        return array_replace([
            'schema' => 'siro.theme/1',
            'id' => $id,
            'name' => $name,
            'version' => '1.0.0',
            'homepage' => ['sections' => [['id' => 'hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => []]]],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function proPackage(): array
    {
        return $this->package('siro/atelier-noir', 'Atelier Noir', [
            'license' => ['required' => true, 'sku' => 'siro-theme-atelier-noir'],
        ]);
    }

    /** @return array<string, mixed> */
    private function configRow(): array
    {
        $row = Database::table('storefront_configs')->where('store_id', 'default')->first();
        self::assertIsArray($row);

        return $row;
    }

    private function activate(string $json): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'license-' . bin2hex(random_bytes(3)) . '.json';
        self::assertNotFalse(file_put_contents($file, $json));
        $this->setEnv('EXTENSION_LICENSE_FILE', $file);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_theme',
            'customer_id' => 'cust_1',
            'edition' => 'pro',
            'extensions' => ['siro-theme-atelier-noir'],
            'features' => [],
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'grace_days' => 30,
        ];
        foreach ($overrides as $key => $value) $payload[$key] = $value;

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function signedLicense(array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = '';
        self::assertTrue(openssl_sign($canonical, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return json_encode(['payload' => $payload, 'signature' => base64_encode($signature)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $email = 'theme-admin-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'Theme Admin', 'email' => $email, 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login($email, 'secret123');

        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: array<string, mixed>} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        $decoded = json_decode($output, true);

        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
            return;
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}
