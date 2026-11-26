<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\SizeGuide\Extension as SizeGuideExtension;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * siro/size-guide: scoped category charts, admin upsert/get, public active
 * lookup, validation matrix, scope isolation and QIT-lite packaging.
 */
final class ExtensionSizeGuideTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(SizeGuideExtension::class);
        ExtensionRegistry::reset();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        parent::tearDown();
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, mixed> $body
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

    private function lifecycle(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService(ExtensionDiscovery::classes($this->basePath()));
    }

    /** @return array{access: string, role: string} */
    private function token(string $role = 'viewer'): array
    {
        $email = 'sg-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'sg', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['access' => $tokens->access, 'role' => $role];
    }

    /** @return array<string, string> */
    private function headers(?string $access = null, string $store = 'default', string $channel = 'web', ?string $key = null): array
    {
        $headers = [
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
            'Idempotency-Key' => $key ?? ('sg-' . bin2hex(random_bytes(6))),
        ];
        if ($access !== null && $access !== '') {
            $headers['Authorization'] = 'Bearer ' . $access;
        }
        return $headers;
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

    private function enableSizeGuide(): void
    {
        $service = $this->lifecycle();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new SizeGuideExtension())->manifest()));
        $service->enable(SizeGuideExtension::id());
        $this->remountRoutes();
    }

    private function remountRoutes(): void
    {
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require $this->basePath() . '/routes/api.php';
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function chartBody(array $overrides = []): array
    {
        $body = [
            'title' => 'Apparel size guide',
            'columns' => ['Size', 'Chest (cm)', 'Length (cm)'],
            'rows' => [
                ['S', '96', '68'],
                ['M', '102', '70'],
                ['L', '108', '72'],
            ],
            'note' => 'Measurements are approximate; size up for a relaxed fit.',
            'active' => true,
        ];
        return array_merge($body, $overrides);
    }

    public function testManifestAndDiscoveryDeclareScopedSizeGuide(): void
    {
        self::assertContains(SizeGuideExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new SizeGuideExtension())->manifest();

        self::assertSame('siro/size-guide', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['size-guide:read', 'size-guide:manage'], $manifest['capabilities']);
        self::assertSame(['size-guide:manage'], $manifest['permissions']);
        self::assertSame(['pdp'], $manifest['slots']['storefront']);
        self::assertSame('migrations/20261124000002_create_ext_size_guide.php', $manifest['migrations'][0]);
        self::assertSame('admin/charts', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('size-guide:manage', $manifest['admin']['panels'][0]['permission']);
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableSizeGuide();

        $anonymous = $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->scopeHeaders());
        self::assertSame(401, $anonymous['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $writeDenied = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody(), $this->headers($viewer['access']));
        self::assertSame(403, $writeDenied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
        self::assertIsArray($allowed['json']);
        self::assertSame([], $allowed['json']['data']);
    }

    public function testUpsertGetIsIdempotentAndScopeIsolated(): void
    {
        $this->enableSizeGuide();
        $admin = $this->token('admin');
        $key = 'sg-upsert-' . bin2hex(random_bytes(6));

        $first = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody(), $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $first['status']);
        self::assertIsArray($first['json']);
        $row = $first['json']['data'];
        self::assertSame('apparel', $row['category']);
        self::assertSame('Apparel size guide', $row['title']);
        self::assertSame(['Size', 'Chest (cm)', 'Length (cm)'], $row['columns']);
        self::assertSame([['S', '96', '68'], ['M', '102', '70'], ['L', '108', '72']], $row['rows']);
        self::assertTrue($row['active']);

        $replay = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody(), $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $replay['status']);
        self::assertIsArray($replay['json']);
        self::assertSame($row['id'], $replay['json']['data']['id']);

        $list = $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($admin['access']));
        self::assertSame(200, $list['status']);
        self::assertIsArray($list['json']);
        self::assertCount(1, $list['json']['data']);
        self::assertSame($row['id'], $list['json']['data'][0]['id']);

        $public = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->headers(), ['category' => 'apparel']);
        self::assertSame(200, $public['status']);
        self::assertIsArray($public['json']);
        self::assertIsArray($public['json']['data']);
        self::assertSame('Apparel size guide', $public['json']['data']['title']);
        self::assertArrayNotHasKey('store_id', $public['json']['data']);
        self::assertArrayNotHasKey('channel_id', $public['json']['data']);

        $otherStoreList = $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame([], $otherStoreList['json']['data']);
        $otherChannelList = $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($admin['access'], 'default', 'mobile'));
        self::assertSame([], $otherChannelList['json']['data']);

        $otherPublic = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->headers(null, 'store-b', 'web'), ['category' => 'apparel']);
        self::assertSame(200, $otherPublic['status']);
        self::assertNull($otherPublic['json']['data']);

        $otherSave = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody(['title' => 'Store B chart']), $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherSave['status']);
        $original = $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($admin['access']));
        self::assertSame('Apparel size guide', $original['json']['data'][0]['title']);
    }

    public function testPublicReturnsActiveChartOnly(): void
    {
        $this->enableSizeGuide();
        $admin = $this->token('admin');

        $before = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->scopeHeaders(), ['category' => 'apparel']);
        self::assertSame(200, $before['status']);
        self::assertIsArray($before['json']);
        self::assertNull($before['json']['data']);

        $saved = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody(), $this->headers($admin['access']));
        self::assertSame(200, $saved['status']);

        $active = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->scopeHeaders(), ['category' => 'apparel']);
        self::assertSame(200, $active['status']);
        self::assertIsArray($active['json']);
        self::assertIsArray($active['json']['data']);
        self::assertSame('Measurements are approximate; size up for a relaxed fit.', $active['json']['data']['note']);

        $inactive = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody(['active' => false]), $this->headers($admin['access']));
        self::assertSame(200, $inactive['status']);
        self::assertFalse($inactive['json']['data']['active']);

        $hidden = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->scopeHeaders(), ['category' => 'apparel']);
        self::assertSame(200, $hidden['status']);
        self::assertNull($hidden['json']['data']);
    }

    public function testInvalidChartsReturn422(): void
    {
        $this->enableSizeGuide();
        $admin = $this->token('admin');

        $invalidBodies = [
            ['title' => ''],
            ['title' => '<b>Apparel</b>'],
            ['columns' => []],
            ['columns' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']],
            ['columns' => ['Size', 123, 'Length']],
            ['columns' => ['Size', '', 'Length']],
            ['columns' => ['Size', '<b>Chest</b>', 'Length']],
            ['rows' => []],
            ['rows' => array_fill(0, 51, ['S', '96', '68'])],
            ['rows' => ['S', '96', '68']],
            ['rows' => [['S', '96']]],
            ['rows' => [['S', '<script>alert(1)</script>', '68']]],
            ['note' => str_repeat('n', 301)],
            ['note' => '<b>note</b>'],
            ['active' => 'maybe'],
        ];
        foreach ($invalidBodies as $override) {
            $response = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody($override), $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }

        $badCategory = $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/Men_Apparel', $this->chartBody(), $this->headers($admin['access']));
        self::assertSame(422, $badCategory['status']);

        $missingCategory = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->scopeHeaders());
        self::assertSame(422, $missingCategory['status']);

        $badQuery = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->scopeHeaders(), ['category' => 'Men_Apparel']);
        self::assertSame(422, $badQuery['status']);

        $tooLong = $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->scopeHeaders(), ['category' => str_repeat('a', 161)]);
        self::assertSame(422, $tooLong['status']);
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableSizeGuide();
        $admin = $this->token('admin');
        self::assertSame(200, $this->call('PUT', '/api/ext/siro/size-guide/admin/charts/apparel', $this->chartBody(), $this->headers($admin['access']))['status']);
        self::assertSame(200, $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($admin['access']))['status']);

        $lifecycle = $this->lifecycle();
        $lifecycle->disable(SizeGuideExtension::id());
        $this->remountRoutes();

        self::assertSame(404, $this->call('GET', '/api/ext/siro/size-guide/admin/charts', [], $this->headers($admin['access']))['status']);
        self::assertSame(404, $this->call('GET', '/api/ext/siro/size-guide/public', [], $this->scopeHeaders(), ['category' => 'apparel'])['status']);
        self::assertSame(200, $this->call('GET', '/health/live')['status']);

        $lifecycle->uninstall(SizeGuideExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_size_guide_charts'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $products = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['products'], $products);
    }

    public function testExtensionPackagePassesQualityGate(): void
    {
        $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-size-guide-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $source = $this->basePath() . '/app/Extensions/Siro/SizeGuide';
            $packed = (new ExtensionPackageInstaller($this->basePath()))->pack($source, $output);
            self::assertTrue($packed['ok']);
            self::assertFileExists($output);

            $report = (new ExtensionQualityGate($this->basePath()))->run($output);
            self::assertTrue($report['ok'], (string) json_encode($report));
            $checks = array_column($report['checks'], 'ok', 'name');
            foreach (['package', 'signature', 'store-scope', 'route-auth', 'secret-scan', 'php-lint'] as $name) {
                self::assertArrayHasKey($name, $checks);
                self::assertTrue($checks[$name], 'QIT check failed: ' . $name);
            }
        } finally {
            @unlink($output);
        }
    }
}
