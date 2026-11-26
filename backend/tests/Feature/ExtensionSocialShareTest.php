<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\SocialShare\Extension as SocialShareExtension;
use App\Extensions\Siro\SocialShare\SocialShareService;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Application\ExtensionRouteLoader;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * siro/social-share: scoped settings, public storefront payload, auth matrix,
 * networks validation, scope isolation, packaging and QIT-lite.
 */
final class ExtensionSocialShareTest extends TestCase
{
    private Router $router;

    /** @var list<Router> Keep retired routers alive so spl_object_id is not reused by the loader. */
    private array $retiredRouters = [];

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(SocialShareExtension::class);
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
        $email = 'share-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'share', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
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
            'Idempotency-Key' => $idempotencyKey ?? ('share-' . bin2hex(random_bytes(6))),
        ];
    }

    /** @return array<string, string> */
    private function scopeHeaders(string $store = 'default', string $channel = 'web'): array
    {
        return [
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];
    }

    private function enableSocialShare(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new SocialShareExtension())->manifest()));
        $service->enable(SocialShareExtension::id());
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
    private function settingsBody(): array
    {
        return [
            'enabled' => true,
            'networks' => SocialShareService::DEFAULT_NETWORKS,
            'label' => 'Share this product',
        ];
    }

    public function testManifestAndMigrationDeclareScopedSocialShare(): void
    {
        self::assertContains(SocialShareExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new SocialShareExtension())->manifest();
        self::assertSame('siro/social-share', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['social-share:read', 'social-share:manage'], $manifest['capabilities']);
        self::assertSame(['social-share:manage'], $manifest['permissions']);
        self::assertSame('migrations/20261124000001_create_ext_social_share.php', $manifest['migrations'][0]);
        self::assertContains('pdp', $manifest['slots']['storefront']);
        self::assertSame('admin/settings', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('social-share:manage', $manifest['admin']['panels'][0]['permission']);

        $raw = file_get_contents($this->basePath() . '/app/Extensions/Siro/SocialShare/manifest.json');
        self::assertIsString($raw);
        $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame('siro.extension/2', $package['schema'] ?? null);
        self::assertSame('siro/social-share', $package['id'] ?? null);
        self::assertSame('module', $package['type'] ?? null);
        self::assertSame(['pdp'], $package['slots']['storefront'] ?? null);

        $this->enableSocialShare();
        $statement = Database::connection()->query(
            'SELECT id, store_id, channel_id, enabled, networks, label, created_at, updated_at FROM ext_social_share LIMIT 0'
        );
        self::assertNotFalse($statement);
        self::assertSame(8, $statement->columnCount());
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableSocialShare();

        $noAuth = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->scopeHeaders());
        self::assertSame(401, $noAuth['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);
        $writeDenied = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', $this->settingsBody(), $this->headers($viewer['access']));
        self::assertSame(403, $writeDenied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
        self::assertIsArray($allowed['json']);
        self::assertFalse($allowed['json']['data']['enabled']);
        self::assertSame(SocialShareService::DEFAULT_NETWORKS, $allowed['json']['data']['networks']);
        self::assertArrayHasKey('table', $allowed['json']['data']);
    }

    public function testSettingsSaveGetIsIdempotentAndScoped(): void
    {
        $this->enableSocialShare();
        $admin = $this->token('admin');
        $body = $this->settingsBody();

        $first = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', $body, $this->headers($admin['access']));
        self::assertSame(200, $first['status'], (string) json_encode($first['json']));
        self::assertIsArray($first['json']);
        self::assertTrue($first['json']['data']['enabled']);
        self::assertSame($body['networks'], $first['json']['data']['networks']);
        self::assertSame('Share this product', $first['json']['data']['label']);

        $replay = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', $body, $this->headers($admin['access']));
        self::assertSame(200, $replay['status']);
        self::assertIsArray($replay['json']);
        self::assertSame($first['json']['data']['id'], $replay['json']['data']['id']);

        $same = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $same['status']);
        self::assertIsArray($same['json']);
        self::assertTrue($same['json']['data']['enabled']);
        self::assertSame($body['networks'], $same['json']['data']['networks']);
        self::assertSame('Share this product', $same['json']['data']['label']);

        $otherStore = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertFalse($otherStore['json']['data']['enabled']);
        self::assertSame(SocialShareService::DEFAULT_NETWORKS, $otherStore['json']['data']['networks']);

        $otherChannel = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($admin['access'], 'default', 'mobile'));
        self::assertSame(200, $otherChannel['status']);
        self::assertIsArray($otherChannel['json']);
        self::assertFalse($otherChannel['json']['data']['enabled']);

        $otherSave = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', [
            'enabled' => true,
            'networks' => ['copy'],
            'label' => 'Store B share',
        ], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherSave['status']);

        $original = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($admin['access']));
        self::assertIsArray($original['json']);
        self::assertSame($body['networks'], $original['json']['data']['networks']);
        self::assertSame('Share this product', $original['json']['data']['label']);
    }

    public function testPublicPayloadActiveDisabledAndScoped(): void
    {
        $this->enableSocialShare();
        $admin = $this->token('admin');

        $inactive = $this->call('GET', '/api/ext/siro/social-share/public', [], $this->scopeHeaders());
        self::assertSame(200, $inactive['status']);
        self::assertIsArray($inactive['json']);
        self::assertFalse($inactive['json']['data']['enabled']);
        self::assertSame([], $inactive['json']['data']['networks']);
        self::assertSame(SocialShareService::DEFAULT_LABEL, $inactive['json']['data']['label']);

        $this->call('PUT', '/api/ext/siro/social-share/admin/settings', $this->settingsBody(), $this->headers($admin['access']));

        $active = $this->call('GET', '/api/ext/siro/social-share/public', [], $this->scopeHeaders());
        self::assertSame(200, $active['status']);
        self::assertIsArray($active['json']);
        self::assertTrue($active['json']['data']['enabled']);
        self::assertSame(SocialShareService::DEFAULT_NETWORKS, $active['json']['data']['networks']);
        self::assertSame('Share this product', $active['json']['data']['label']);

        $otherStore = $this->call('GET', '/api/ext/siro/social-share/public', [], $this->scopeHeaders('store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertFalse($otherStore['json']['data']['enabled']);
        self::assertSame([], $otherStore['json']['data']['networks']);

        $disable = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', ['enabled' => false], $this->headers($admin['access']));
        self::assertSame(200, $disable['status']);
        $disabled = $this->call('GET', '/api/ext/siro/social-share/public', [], $this->scopeHeaders());
        self::assertSame(200, $disabled['status']);
        self::assertIsArray($disabled['json']);
        self::assertFalse($disabled['json']['data']['enabled']);
        self::assertSame([], $disabled['json']['data']['networks']);
    }

    public function testInvalidNetworksAndLabelReturn422(): void
    {
        $this->enableSocialShare();
        $admin = $this->token('admin');
        $cases = [
            ['networks' => 'facebook'],
            ['networks' => ['facebook', 'facebook']],
            ['networks' => ['tiktok']],
            ['networks' => ['facebook', 1]],
            ['networks' => ['facebook' => 'x']],
            ['networks' => array_merge(SocialShareService::DEFAULT_NETWORKS, ['facebook'])],
            ['label' => ''],
            ['label' => str_repeat('x', 61)],
            ['label' => 123],
            ['enabled' => 'maybe'],
        ];
        foreach ($cases as $override) {
            $body = array_merge($this->settingsBody(), $override);
            $response = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', $body, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }

        $emptyNetworks = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', [
            'enabled' => true,
            'networks' => [],
            'label' => 'Share',
        ], $this->headers($admin['access']));
        self::assertSame(200, $emptyNetworks['status']);
        self::assertIsArray($emptyNetworks['json']);
        self::assertSame([], $emptyNetworks['json']['data']['networks']);
    }

    public function testServiceScopesRowsAndDecodesNetworks(): void
    {
        $this->enableSocialShare();
        $service = new SocialShareService();
        $scope = new CommerceScope('svc-store', 'svc-channel', 'en', 'USD');
        $saved = $service->save($scope, [
            'enabled' => '1',
            'networks' => ['facebook', 'x', 'copy'],
            'label' => 'Share now',
        ]);
        self::assertTrue($saved['enabled']);
        self::assertSame(['facebook', 'x', 'copy'], $saved['networks']);
        self::assertSame('Share now', $saved['label']);
        self::assertSame(['facebook', 'x', 'copy'], $service->publicData($scope)['networks']);
        self::assertFalse($service->publicData(new CommerceScope('other', 'svc-channel', 'en', 'USD'))['enabled']);
    }

    public function testMutationRequiresIdempotencyKey(): void
    {
        $this->enableSocialShare();
        $admin = $this->token('admin');
        $headers = $this->headers($admin['access']);
        unset($headers['Idempotency-Key']);
        $response = $this->call('PUT', '/api/ext/siro/social-share/admin/settings', $this->settingsBody(), $headers);
        self::assertSame(422, $response['status']);
    }

    public function testPackageAndQitLitePass(): void
    {
        $dir = $this->basePath() . '/app/Extensions/Siro/SocialShare';
        $zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'social-share-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $pack = (new ExtensionPackageInstaller($this->basePath()))->pack($dir, $zip);
            self::assertTrue($pack['ok']);
            $report = (new ExtensionQualityGate($this->basePath()))->run($zip);
            self::assertTrue($report['ok'], (string) json_encode($report));
            $checks = array_column($report['checks'], 'ok', 'name');
            self::assertTrue($checks['package']);
            self::assertTrue($checks['store-scope']);
            self::assertTrue($checks['route-auth']);
            self::assertTrue($checks['secret-scan']);
            self::assertTrue($checks['php-lint']);
        } finally {
            if (is_file($zip)) {
                @unlink($zip);
            }
        }
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableSocialShare();
        $admin = $this->token('admin');
        $this->call('PUT', '/api/ext/siro/social-share/admin/settings', $this->settingsBody(), $this->headers($admin['access']));

        $ok = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(SocialShareExtension::id());
        $this->remountRoutes();
        $gone = $this->call('GET', '/api/ext/siro/social-share/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
        $publicGone = $this->call('GET', '/api/ext/siro/social-share/public', [], $this->scopeHeaders());
        self::assertSame(404, $publicGone['status']);
        $health = $this->call('GET', '/health/live');
        self::assertSame(200, $health['status']);

        $lifecycle->uninstall(SocialShareExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_social_share'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $orders = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='orders'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['orders'], $orders);
    }
}
