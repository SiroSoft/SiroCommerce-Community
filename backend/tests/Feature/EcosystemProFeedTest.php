<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EcosystemCatalogController;
use App\Extension\ExtensionRegistry;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\EcosystemCatalogService;
use App\Modules\Extension\Application\ExtensionLicenseService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * P2 private Pro feed: entitlement gate, Pro-only metadata, safe HTTP errors
 * and regression coverage for the public catalog read model.
 */
final class EcosystemProFeedTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
    ];

    private Router $router;

    /** @var array<string, string> */
    private array $originalEnv = [];

    /** @var array<string, string> */
    private array $originalPutenv = [];

    private string $tempDir;

    private int $licenseCounter = 0;

    /** @var \OpenSSLAsymmetricKey */
    private mixed $privateKey;

    private string $publicKeyPem;

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::reset();

        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) {
                $env = $_ENV[$key];
                $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            }
            $putenv = getenv($key);
            if ($putenv !== false) {
                $this->originalPutenv[$key] = $putenv;
            }
            $this->setEnv($key, null);
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-pro-feed-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for Pro feed tests');
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        $this->privateKey = $key;
        $this->publicKeyPem = $details['key'];
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $this->publicKeyPem);
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();

        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $this->originalEnv)) {
                $this->setEnv($key, $this->originalEnv[$key]);
            } else {
                $this->setEnv($key, null);
            }
        }

        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function testNoLicenseIsForbidden(): void
    {
        $response = $this->controllerResponse();

        self::assertSame(403, $response['status']);
        self::assertSame(
            EcosystemCatalogService::PRO_FEED_LICENSE_REQUIRED,
            $response['json']['message'] ?? null
        );

        try {
            (new EcosystemCatalogService($this->base()))->proFeed();
            self::fail('Pro feed without a license must fail');
        } catch (\RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame(EcosystemCatalogService::PRO_FEED_LICENSE_REQUIRED, $e->getMessage());
        }
    }

    public function testValidLicenseReturnsOnlyProItems(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['siro/affiliate'],
        ])));

        $response = $this->controllerResponse();
        self::assertSame(200, $response['status']);

        $data = $response['data'];
        self::assertSame(EcosystemCatalogService::SCHEMA, $data['schema'] ?? null);
        self::assertNotSame('', $data['generated_at'] ?? '');
        self::assertIsArray($data['items'] ?? null);
        self::assertNotEmpty($data['items']);

        $ids = [];
        foreach ($data['items'] as $item) {
            self::assertIsArray($item);
            self::assertSame('pro', $item['edition'] ?? null);
            $ids[] = $item['id'] ?? null;
        }
        self::assertContains('siro/affiliate', $ids);
        self::assertNotContains('siro/product-qa', $ids);
        self::assertNotContains('siro/fashion', $ids);

        self::assertSame(['edition' => 'pro', 'status' => 'valid'], $data['license'] ?? null);
    }

    public function testGraceLicenseIsAllowed(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['siro/affiliate'],
            'expires_at' => time() - 3600,
            'grace_days' => 30,
        ])));

        $response = $this->controllerResponse();
        self::assertSame(200, $response['status']);
        self::assertSame(
            ['edition' => 'pro', 'status' => 'grace'],
            $response['data']['license'] ?? null
        );
    }

    public function testExpiredLicenseIsForbidden(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['siro/affiliate'],
            'expires_at' => time() - (10 * 86400),
            'grace_days' => 5,
        ])));

        $response = $this->controllerResponse();
        self::assertSame(403, $response['status']);
        self::assertSame(
            EcosystemCatalogService::PRO_FEED_LICENSE_REQUIRED,
            $response['json']['message'] ?? null
        );
    }

    public function testInvalidLicenseIsForbidden(): void
    {
        $this->activate(json_encode([
            'payload' => $this->payload(['extensions' => ['siro/affiliate']]),
            'signature' => 'not-a-valid-signature',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $response = $this->controllerResponse();
        self::assertSame(403, $response['status']);
    }

    public function testCommunityEditionIsForbidden(): void
    {
        $status = (new ExtensionLicenseService())->status();
        self::assertSame('community', $status['edition']);
        self::assertTrue($status['ok']);

        $response = $this->controllerResponse();
        self::assertSame(403, $response['status']);
        self::assertSame(
            EcosystemCatalogService::PRO_FEED_LICENSE_REQUIRED,
            $response['json']['message'] ?? null
        );
    }

    public function testProFeedDoesNotLeakSecrets(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'license_id' => 'lic_top_secret',
            'extensions' => ['siro/affiliate'],
            'features' => ['secret-feature'],
        ])));

        $response = $this->controllerResponse();
        self::assertSame(200, $response['status']);

        $data = $response['data'];
        self::assertIsArray($data['license'] ?? null);
        self::assertSame(['edition', 'status'], array_keys($data['license']));
        self::assertArrayNotHasKey('license_id', $data['license']);
        self::assertArrayNotHasKey('extensions', $data['license']);
        self::assertArrayNotHasKey('signature', $data);

        $encoded = (string) json_encode($response['json']);
        self::assertStringNotContainsString('lic_top_secret', $encoded);
        self::assertStringNotContainsString('secret-feature', $encoded);
        self::assertArrayNotHasKey('signature', $data);
        self::assertStringNotContainsString('BEGIN PUBLIC KEY', $encoded);
        self::assertStringNotContainsString($this->publicKeyPem, $encoded);
        self::assertStringNotContainsString($this->tempDir, $encoded);
        self::assertStringNotContainsString($this->base(), $encoded);
    }

    public function testCatalogErrorIsSafeAndMapsTo500(): void
    {
        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['siro/affiliate'],
        ])));

        $badPath = tempnam(sys_get_temp_dir(), 'siro-pro-feed-bad-');
        self::assertIsString($badPath);
        file_put_contents($badPath, '{"schema":');

        try {
            $service = new EcosystemCatalogService($this->base(), $badPath);
            try {
                $service->proFeed();
                self::fail('Malformed catalog must fail');
            } catch (\RuntimeException $e) {
                self::assertNotSame(403, $e->getCode());
                self::assertStringContainsStringIgnoringCase('malformed', $e->getMessage());
                self::assertStringNotContainsString($badPath, $e->getMessage());
                self::assertStringNotContainsString($this->base(), $e->getMessage());
            }

            $response = $this->controllerResponse($service);
            self::assertSame(500, $response['status']);
            self::assertSame('Ecosystem catalog is unavailable', $response['json']['message'] ?? null);

            $encoded = (string) json_encode($response['json']);
            self::assertStringNotContainsString($badPath, $encoded);
            self::assertStringNotContainsString(dirname($badPath), $encoded);
        } finally {
            @unlink($badPath);
        }
    }

    public function testCatalogAndUpdatesBehaviorUnchanged(): void
    {
        $service = new EcosystemCatalogService($this->base());
        $all = $service->catalog();
        $free = $service->catalog('all', 'free');
        $pro = $service->catalog('all', 'pro');
        $plugins = $service->catalog('plugin');
        $themes = $service->catalog('theme');
        $search = $service->catalog('all', 'all', '  AFFILIATE  ');
        $updates = $service->updates();

        self::assertNotEmpty($all['items']);
        self::assertCount(count($all['items']), [...$free['items'], ...$pro['items']]);
        foreach ($pro['items'] as $item) {
            self::assertSame('pro', $item['edition']);
        }
        foreach ($free['items'] as $item) {
            self::assertSame('free', $item['edition']);
        }
        foreach ($plugins['items'] as $item) {
            self::assertSame('plugin', $item['type']);
        }
        foreach ($themes['items'] as $item) {
            self::assertSame('theme', $item['type']);
        }
        self::assertSame(['siro/affiliate'], array_column($search['items'], 'id'));
        foreach ($updates['items'] as $item) {
            self::assertTrue($item['update_available']);
        }

        $this->activate($this->signedLicense($this->payload([
            'extensions' => ['siro/affiliate'],
        ])));
        $feed = (new EcosystemCatalogService($this->base()))->proFeed();

        self::assertSame(
            array_column($pro['items'], 'id'),
            array_column($feed['items'], 'id')
        );
    }

    public function testAuthRejectsAnonymousAndViewerWhenRouteMounted(): void
    {
        if (!$this->routeRegistered('GET', '/api/admin/ecosystem/pro-feed')) {
            self::markTestSkipped('Pro feed route is not mounted yet; routes/api.php is owned by P2-A.');
        }

        self::assertSame(401, $this->call('GET', '/api/admin/ecosystem/pro-feed')['status']);
        self::assertSame(
            403,
            $this->call('GET', '/api/admin/ecosystem/pro-feed', [], $this->headers('viewer'))['status']
        );
    }

    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private function request(): Request
    {
        return new Request('GET', '/api/admin/ecosystem/pro-feed', [], [], [], '127.0.0.1');
    }

    /**
     * @return array{status: int, json: array<string, mixed>, data: array<string, mixed>}
     */
    private function controllerResponse(?EcosystemCatalogService $service = null): array
    {
        $response = (new EcosystemCatalogController($service))->proFeed($this->request());
        $payload = $response->payload();
        $data = $payload['data'] ?? null;

        return [
            'status' => $response->statusCode(),
            'json' => $payload,
            'data' => is_array($data) ? $data : [],
        ];
    }

    private function routeRegistered(string $method, string $path): bool
    {
        foreach ($this->router->getRoutes() as $route) {
            if (($route['method'] ?? '') === strtoupper($method) && ($route['path'] ?? '') === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_test',
            'customer_id' => 'cust_1',
            'edition' => 'pro',
            'extensions' => ['siro/affiliate'],
            'features' => [],
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'grace_days' => 30,
        ];

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function signedLicense(array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = '';
        self::assertTrue(openssl_sign($canonical, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return json_encode(
            ['payload' => $payload, 'signature' => base64_encode($signature)],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function activate(string $json): void
    {
        $this->licenseCounter++;
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'active-' . $this->licenseCounter . '.json';
        self::assertNotFalse(file_put_contents($file, $json));
        $this->setEnv('EXTENSION_LICENSE_FILE', $file);
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

    /**
     * @return array<string, string>
     */
    private function headers(string $role): array
    {
        $email = 'pro-feed-' . $role . '-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');

        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, json: array<string, mixed>}
     */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        $route = $path;
        if (str_contains($path, '?')) {
            [$route, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }

        $request = new Request($method, $route, $query, $headers, $body, '127.0.0.1');
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
}
