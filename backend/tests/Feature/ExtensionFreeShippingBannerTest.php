<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\FreeShippingBanner\Extension as FreeShippingBannerExtension;
use App\Extensions\Siro\FreeShippingBanner\FreeShippingBannerService;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionRouteLoader;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ExtensionFreeShippingBannerTest extends TestCase
{
    private Router $router;

    /** @var list<Router> Keep retired routers alive so spl_object_id is not reused by the loader. */
    private array $retiredRouters = [];

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(FreeShippingBannerExtension::class);
        ExtensionRegistry::reset();
        $this->resetMountedRoutes();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        $this->resetMountedRoutes();
        parent::tearDown();
    }

    private function resetMountedRoutes(): void
    {
        $property = (new \ReflectionClass(ExtensionRouteLoader::class))->getProperty('mounted');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{status: int, json: mixed}
     */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            $response = $exception->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    private function service(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService(ExtensionDiscovery::classes($this->basePath()));
    }

    /** @return array{access: string, role: string} */
    private function token(string $role = 'viewer'): array
    {
        $email = 'banner-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'banner', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['access' => $tokens->access, 'role' => $role];
    }

    /** @return array<string, string> */
    private function headers(string $access, string $store = 'default', string $channel = 'web', ?string $idempotencyKey = null): array
    {
        return [
            'Authorization' => 'Bearer ' . $access,
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
            'Idempotency-Key' => $idempotencyKey ?? ('banner-' . bin2hex(random_bytes(6))),
        ];
    }

    private function enableBanner(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new FreeShippingBannerExtension())->manifest()));
        $service->enable(FreeShippingBannerExtension::id());
        $this->remountRoutes();
    }

    private function remountRoutes(): void
    {
        $this->retiredRouters[] = $this->router;
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require $this->basePath() . '/routes/api.php';
    }

    /** @return array<string, mixed> */
    private function bannerBody(bool $active = true): array
    {
        return [
            'threshold_minor' => 500000,
            'message' => 'Free shipping on orders over 500,000 VND',
            'currency' => 'VND',
            'active' => $active,
        ];
    }

    public function testManifestAndMigrationDeclareScopedBanner(): void
    {
        self::assertContains(FreeShippingBannerExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new FreeShippingBannerExtension())->manifest();
        self::assertSame('siro/free-shipping-banner', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['free-shipping:read', 'free-shipping:manage'], $manifest['capabilities']);
        self::assertSame(['free-shipping:manage'], $manifest['permissions']);
        self::assertSame('migrations/20261123000001_create_ext_free_shipping_banner.php', $manifest['migrations'][0]);
        self::assertSame('admin/settings', $manifest['admin']['panels'][0]['endpoint']);

        $raw = file_get_contents($this->basePath() . '/app/Extensions/Siro/FreeShippingBanner/manifest.json');
        self::assertIsString($raw);
        $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame('siro.extension/2', $package['schema'] ?? null);
        self::assertSame('siro/free-shipping-banner', $package['id'] ?? null);
        self::assertSame('module', $package['type'] ?? null);
        self::assertSame(['cart'], $package['slots']['storefront'] ?? null);

        $this->enableBanner();
        $statement = Database::connection()->query(
            'SELECT id, store_id, channel_id, threshold_minor, message, active, currency, created_at, updated_at FROM ext_free_shipping_banner LIMIT 0'
        );
        self::assertNotFalse($statement);
        self::assertSame(9, $statement->columnCount());
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableBanner();
        $noAuth = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        self::assertSame(401, $noAuth['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
        self::assertIsArray($allowed['json']);
        self::assertFalse($allowed['json']['data']['active']);
        self::assertArrayHasKey('table', $allowed['json']['data']);
    }

    public function testSettingsSaveAndGetAreScopedAndIdempotent(): void
    {
        $this->enableBanner();
        $admin = $this->token('admin');
        $key = 'banner-save-' . bin2hex(random_bytes(6));
        $body = $this->bannerBody();

        $first = $this->call('PUT', '/api/ext/siro/free-shipping-banner/admin/settings', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $first['status']);
        self::assertIsArray($first['json']);
        self::assertSame(500000, $first['json']['data']['threshold_minor']);
        self::assertTrue($first['json']['data']['active']);

        $replay = $this->call('PUT', '/api/ext/siro/free-shipping-banner/admin/settings', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $replay['status']);
        self::assertIsArray($replay['json']);
        self::assertSame($first['json']['data']['id'], $replay['json']['data']['id']);

        $same = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $same['status']);
        self::assertIsArray($same['json']);
        self::assertSame('Free shipping on orders over 500,000 VND', $same['json']['data']['message']);
        self::assertSame('VND', $same['json']['data']['currency']);

        $otherStore = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertFalse($otherStore['json']['data']['active']);
        self::assertSame(0, $otherStore['json']['data']['threshold_minor']);

        $otherChannel = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], $this->headers($admin['access'], 'default', 'mobile'));
        self::assertSame(200, $otherChannel['status']);
        self::assertIsArray($otherChannel['json']);
        self::assertFalse($otherChannel['json']['data']['active']);

        $public = $this->call('GET', '/api/ext/siro/free-shipping-banner/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $public['status'], json_encode($public['json']));
        self::assertIsArray($public['json']);
        self::assertTrue($public['json']['data']['active']);
        self::assertSame('Free shipping on orders over 500,000 VND', $public['json']['data']['message']);
        self::assertSame(500000, $public['json']['data']['threshold_minor']);
        self::assertSame(500000, $public['json']['data']['threshold']);

        $publicOther = $this->call('GET', '/api/ext/siro/free-shipping-banner/public', [], [], ['store_id' => 'store-b', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $publicOther['status']);
        self::assertIsArray($publicOther['json']);
        self::assertFalse($publicOther['json']['data']['active']);
        self::assertSame('', $publicOther['json']['data']['message']);
    }

    public function testPublicReflectsInactiveBannerAndServiceScope(): void
    {
        $this->enableBanner();
        $admin = $this->token('admin');
        $body = $this->bannerBody(false);
        $saved = $this->call('PUT', '/api/ext/siro/free-shipping-banner/admin/settings', $body, $this->headers($admin['access']));
        self::assertSame(200, $saved['status']);

        $public = $this->call('GET', '/api/ext/siro/free-shipping-banner/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $public['status'], json_encode($public['json']));
        self::assertIsArray($public['json']);
        self::assertFalse($public['json']['data']['active']);
        self::assertSame('', $public['json']['data']['message']);

        $service = new FreeShippingBannerService();
        $scope = new CommerceScope('svc-store', 'svc-channel', 'en', 'USD');
        $row = $service->save($scope, [
            'threshold_minor' => 250000,
            'message' => 'Free shipping over 250 USD',
            'currency' => 'usd',
            'active' => '1',
        ]);
        self::assertSame('USD', $row['currency']);
        self::assertTrue($row['active']);
        self::assertTrue($service->publicSettings($scope)['active']);
        self::assertFalse($service->publicSettings(new CommerceScope('other', 'svc-channel', 'en', 'USD'))['active']);
    }

    public function testInvalidSettingsReturn422(): void
    {
        $this->enableBanner();
        $admin = $this->token('admin');
        $cases = [
            ['threshold_minor' => -1],
            ['threshold_minor' => null],
            ['threshold_minor' => 'abc'],
            ['message' => 'x'],
            ['message' => str_repeat('m', 281)],
            ['currency' => 'VNDX'],
            ['currency' => '12'],
            ['active' => 'yes'],
            ['active' => null],
        ];
        foreach ($cases as $override) {
            $body = array_merge($this->bannerBody(), $override);
            $response = $this->call('PUT', '/api/ext/siro/free-shipping-banner/admin/settings', $body, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }
    }

    public function testMutationRequiresIdempotencyKey(): void
    {
        $this->enableBanner();
        $admin = $this->token('admin');
        $headers = $this->headers($admin['access']);
        unset($headers['Idempotency-Key']);
        $response = $this->call('PUT', '/api/ext/siro/free-shipping-banner/admin/settings', $this->bannerBody(), $headers);
        self::assertSame(422, $response['status']);
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableBanner();
        $admin = $this->token('admin');
        $service = new FreeShippingBannerService();
        $service->save(new CommerceScope('default', 'web', 'en', 'VND'), $this->bannerBody());

        $ok = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(FreeShippingBannerExtension::id());
        $this->remountRoutes();
        $gone = $this->call('GET', '/api/ext/siro/free-shipping-banner/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
        $publicGone = $this->call('GET', '/api/ext/siro/free-shipping-banner/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(404, $publicGone['status']);
        $health = $this->call('GET', '/health/live');
        self::assertSame(200, $health['status']);

        $lifecycle->uninstall(FreeShippingBannerExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_free_shipping_banner'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $orders = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='orders'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['orders'], $orders);
    }
}
