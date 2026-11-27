<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\StickyAddToCart\Extension as StickyAddToCartExtension;
use App\Extensions\Siro\StickyAddToCart\StickyAddToCartService;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionRouteLoader;
use App\Modules\Extension\Application\ExtensionScope;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ExtensionStickyAddToCartTest extends TestCase
{
    private Router $router;

    /** @var list<Router> Keep retired routers alive so spl_object_id is not reused by the loader. */
    private array $retiredRouters = [];

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(StickyAddToCartExtension::class);
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
        $email = 'sticky-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'sticky', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
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
            'Idempotency-Key' => $idempotencyKey ?? ('sticky-' . bin2hex(random_bytes(6))),
        ];
    }

    private function enableSticky(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new StickyAddToCartExtension())->manifest()));
        $service->enable(StickyAddToCartExtension::id());
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
    private function settingsBody(bool $active = true): array
    {
        return [
            'button_label' => 'Add to Cart now',
            'show_price' => true,
            'active' => $active,
        ];
    }

    public function testManifestAndMigrationDeclareScopedSettings(): void
    {
        self::assertContains(StickyAddToCartExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new StickyAddToCartExtension())->manifest();
        self::assertSame('siro/sticky-add-to-cart', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['sticky-atc:read', 'sticky-atc:manage'], $manifest['capabilities']);
        self::assertSame(['sticky-atc:manage'], $manifest['permissions']);
        self::assertSame('migrations/20261126000001_create_ext_sticky_atc_settings.php', $manifest['migrations'][0]);
        self::assertSame(['pdp.below-atc'], $manifest['slots']['storefront']);
        self::assertSame('admin/stats', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('stats', $manifest['admin']['panels'][0]['type']);
        self::assertSame('admin/settings', $manifest['admin']['panels'][1]['endpoint']);
        self::assertSame('table', $manifest['admin']['panels'][1]['type']);
        self::assertSame(false, (new StickyAddToCartExtension())->config()['default_active']);
        self::assertSame('Add to Cart', (new StickyAddToCartExtension())->config()['default_button_label']);

        $raw = file_get_contents($this->basePath() . '/app/Extensions/Siro/StickyAddToCart/manifest.json');
        self::assertIsString($raw);
        $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame('siro.extension/2', $package['schema'] ?? null);
        self::assertSame('siro/sticky-add-to-cart', $package['id'] ?? null);
        self::assertSame('module', $package['type'] ?? null);
        self::assertSame(['pdp.below-atc'], $package['slots']['storefront'] ?? null);

        $this->enableSticky();
        $statement = Database::connection()->query(
            'SELECT id, store_id, channel_id, button_label, show_price, active, created_at, updated_at FROM ext_sticky_atc_settings LIMIT 0'
        );
        self::assertNotFalse($statement);
        self::assertSame(8, $statement->columnCount());
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableSticky();
        $noAuth = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        self::assertSame(401, $noAuth['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
        self::assertIsArray($allowed['json']);
        self::assertFalse($allowed['json']['data']['active']);
        self::assertSame('Add to Cart', $allowed['json']['data']['button_label']);
        self::assertArrayHasKey('table', $allowed['json']['data']);

        $stats = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/stats', [], $this->headers($admin['access']));
        self::assertSame(200, $stats['status']);
        self::assertIsArray($stats['json']);
        self::assertIsArray($stats['json']['data']['stats'] ?? null);
        self::assertSame('Bar active', $stats['json']['data']['stats'][0]['label']);
        self::assertSame('No', $stats['json']['data']['stats'][0]['value']);
    }

    public function testSettingsSaveAndGetAreScopedAndIdempotent(): void
    {
        $this->enableSticky();
        $admin = $this->token('admin');
        $key = 'sticky-save-' . bin2hex(random_bytes(6));
        $body = $this->settingsBody();

        $first = $this->call('PUT', '/api/ext/siro/sticky-add-to-cart/admin/settings', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $first['status']);
        self::assertIsArray($first['json']);
        self::assertSame('Add to Cart now', $first['json']['data']['button_label']);
        self::assertTrue($first['json']['data']['active']);

        $replay = $this->call('PUT', '/api/ext/siro/sticky-add-to-cart/admin/settings', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $replay['status']);
        self::assertIsArray($replay['json']);
        self::assertSame($first['json']['data']['id'], $replay['json']['data']['id']);

        $same = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $same['status']);
        self::assertIsArray($same['json']);
        self::assertSame('Add to Cart now', $same['json']['data']['button_label']);

        $otherStore = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertFalse($otherStore['json']['data']['active']);
        self::assertSame('Add to Cart', $otherStore['json']['data']['button_label']);

        $otherChannel = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access'], 'default', 'mobile'));
        self::assertSame(200, $otherChannel['status']);
        self::assertIsArray($otherChannel['json']);
        self::assertFalse($otherChannel['json']['data']['active']);

        $public = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $public['status'], json_encode($public['json']));
        self::assertIsArray($public['json']);
        self::assertTrue($public['json']['data']['active']);
        self::assertSame('Add to Cart now', $public['json']['data']['button_label']);
        self::assertTrue($public['json']['data']['show_price']);

        $publicOther = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/public', [], [], ['store_id' => 'store-b', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $publicOther['status']);
        self::assertIsArray($publicOther['json']);
        self::assertFalse($publicOther['json']['data']['active']);
        self::assertSame('', $publicOther['json']['data']['button_label']);
    }

    public function testPublicReflectsInactiveBarAndServiceScope(): void
    {
        $this->enableSticky();
        $admin = $this->token('admin');
        $body = $this->settingsBody(false);
        $saved = $this->call('PUT', '/api/ext/siro/sticky-add-to-cart/admin/settings', $body, $this->headers($admin['access']));
        self::assertSame(200, $saved['status']);

        $public = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $public['status'], json_encode($public['json']));
        self::assertIsArray($public['json']);
        self::assertFalse($public['json']['data']['active']);
        self::assertSame('', $public['json']['data']['button_label']);

        $service = new StickyAddToCartService();
        $scope = new CommerceScope('svc-store', 'svc-channel', 'en', 'USD');
        $row = $service->save($scope, [
            'button_label' => 'Buy now',
            'show_price' => '1',
            'active' => '1',
        ]);
        self::assertSame('Buy now', $row['button_label']);
        self::assertTrue($row['active']);
        self::assertTrue($service->publicSettings($scope)['active']);
        self::assertFalse($service->publicSettings(new CommerceScope('other', 'svc-channel', 'en', 'USD'))['active']);
    }

    public function testInvalidSettingsReturn422(): void
    {
        $this->enableSticky();
        $admin = $this->token('admin');
        $cases = [
            ['button_label' => 'x'],
            ['button_label' => str_repeat('l', 41)],
            ['button_label' => null],
            ['show_price' => 'yes'],
            ['show_price' => null],
            ['active' => 'yes'],
            ['active' => null],
        ];
        foreach ($cases as $override) {
            $body = array_merge($this->settingsBody(), $override);
            $response = $this->call('PUT', '/api/ext/siro/sticky-add-to-cart/admin/settings', $body, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }
    }

    public function testMutationRequiresIdempotencyKey(): void
    {
        $this->enableSticky();
        $admin = $this->token('admin');
        $headers = $this->headers($admin['access']);
        unset($headers['Idempotency-Key']);
        $response = $this->call('PUT', '/api/ext/siro/sticky-add-to-cart/admin/settings', $this->settingsBody(), $headers);
        self::assertSame(422, $response['status']);
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableSticky();
        $admin = $this->token('admin');
        $service = new StickyAddToCartService();
        $service->save(new CommerceScope('default', 'web', 'en', 'VND'), $this->settingsBody());

        $ok = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(StickyAddToCartExtension::id());
        $this->remountRoutes();
        $gone = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
        $publicGone = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/public', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(404, $publicGone['status']);
        $health = $this->call('GET', '/health/live');
        self::assertSame(200, $health['status']);

        $lifecycle->uninstall(StickyAddToCartExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_sticky_atc_settings'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $orders = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='orders'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['orders'], $orders);
    }

    public function testUninstallKeepDataReinstallReusesData(): void
    {
        $this->enableSticky();
        $admin = $this->token('admin');
        $service = new StickyAddToCartService();
        $service->save(new CommerceScope('default', 'web', 'en', 'VND'), $this->settingsBody());

        $lifecycle = $this->service();
        $lifecycle->uninstall(StickyAddToCartExtension::id(), false);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_sticky_atc_settings'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['ext_sticky_atc_settings'], $tables);

        $this->enableSticky();
        $again = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $again['status']);
        self::assertIsArray($again['json']);
        self::assertSame('Add to Cart now', $again['json']['data']['button_label']);
        self::assertTrue($again['json']['data']['active']);
    }

    public function testUpgradeKeepsSettingsAndEndsEnabled(): void
    {
        $this->enableSticky();
        $service = new StickyAddToCartService();
        $service->save(new CommerceScope('default', 'web', 'en', 'VND'), $this->settingsBody());

        $manifest = (new StickyAddToCartExtension())->manifest();
        $manifest['version'] = '1.0.1';
        $lifecycle = $this->service();
        $lifecycle->upgrade(ExtensionManifest::fromArray($manifest));

        $admin = $this->token('admin');
        $again = $this->call('GET', '/api/ext/siro/sticky-add-to-cart/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $again['status']);
        self::assertIsArray($again['json']);
        self::assertSame('Add to Cart now', $again['json']['data']['button_label']);
    }

    public function testVersionMismatchBlocksInstall(): void
    {
        $manifest = (new StickyAddToCartExtension())->manifest();
        $manifest['requires'] = ['core' => '^99.0', 'commerce' => '^0.1'];
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->install(ExtensionManifest::fromArray($manifest));
    }

    public function testExtensionScopeHelperRejectsForeignRows(): void
    {
        [$where, $params] = ExtensionScope::where(['store_id' => 'store-a', 'channel_id' => 'web']);
        self::assertSame('store_id = ? AND channel_id = ?', $where);
        self::assertSame(['store-a', 'web'], $params);

        $scope = new CommerceScope('store-a', 'web', 'en', 'VND');
        ExtensionScope::assertScoped(['store_id' => 'store-a', 'channel_id' => 'web'], $scope);

        $this->expectException(CommerceScopeException::class);
        ExtensionScope::assertScoped(['store_id' => 'store-b', 'channel_id' => 'web'], $scope);
    }
}
