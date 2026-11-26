<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\StoreLocator\Extension as StoreLocatorExtension;
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
 * siro/store-locator: scoped CRUD, active-only public list, auth matrix,
 * validation, install/enable and QIT-lite packaging.
 */
final class ExtensionStoreLocatorTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        // Loads Extension.php, which requires the self-contained src/ classes.
        class_exists(StoreLocatorExtension::class);
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
     * @return array{status: int, json: mixed}
     */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
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
        $email = 'locator-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'locator', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
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
            'Idempotency-Key' => $key ?? ('locator-' . bin2hex(random_bytes(6))),
        ];
        if ($access !== null && $access !== '') {
            $headers['Authorization'] = 'Bearer ' . $access;
        }
        return $headers;
    }

    private function enableStoreLocator(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new StoreLocatorExtension())->manifest()));
        $service->enable(StoreLocatorExtension::id());
        $this->remountRoutes();
    }

    private function remountRoutes(): void
    {
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require $this->basePath() . '/routes/api.php';
    }

    /** @return array<string, mixed> */
    private function locationBody(string $name = 'Siro Flagship'): array
    {
        return [
            'name' => $name,
            'address' => '123 Nguyen Hue',
            'city' => 'Ho Chi Minh City',
            'phone' => '+84 28 1234 5678',
            'hours' => 'Mon-Sat 09:00-21:00',
            'latitude' => 10.7769,
            'longitude' => 106.7009,
            'active' => true,
        ];
    }

    public function testManifestAndMigrationDeclareScopedStoreLocator(): void
    {
        self::assertContains(StoreLocatorExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new StoreLocatorExtension())->manifest();
        self::assertSame('siro/store-locator', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['store-locator:read', 'store-locator:manage'], $manifest['capabilities']);
        self::assertSame(['store-locator:manage'], $manifest['permissions']);
        self::assertSame('migrations/20261124000003_create_ext_store_locator.php', $manifest['migrations'][0]);
        self::assertSame(['footer'], $manifest['slots']['storefront']);
        self::assertSame('table', $manifest['admin']['panels'][0]['type']);
        self::assertSame('admin', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('store-locator:manage', $manifest['admin']['panels'][0]['permission']);

        $raw = file_get_contents($this->basePath() . '/app/Extensions/Siro/StoreLocator/manifest.json');
        self::assertIsString($raw);
        $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame('siro.extension/2', $package['schema'] ?? null);
        self::assertSame('siro/store-locator', $package['id'] ?? null);
        self::assertSame('module', $package['type'] ?? null);
        self::assertSame(['footer'], $package['slots']['storefront'] ?? null);

        $this->enableStoreLocator();
        $statement = Database::connection()->query(
            'SELECT id, store_id, channel_id, name, address, city, phone, hours, latitude, longitude, active, created_at, updated_at FROM ext_store_locations LIMIT 0'
        );
        self::assertNotFalse($statement);
        self::assertSame(13, $statement->columnCount());
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableStoreLocator();

        $anonymous = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers());
        self::assertSame(401, $anonymous['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $writeDenied = $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody(), $this->headers($viewer['access']));
        self::assertSame(403, $writeDenied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);

        $public = $this->call('GET', '/api/ext/siro/store-locator/public', [], $this->headers());
        self::assertSame(200, $public['status']);
    }

    public function testPublicListReturnsOnlyActiveLocationsForTheScope(): void
    {
        $this->enableStoreLocator();
        $admin = $this->token('admin');

        $active = $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody('Active Store'), $this->headers($admin['access']));
        self::assertSame(200, $active['status']);
        self::assertIsArray($active['json']);

        $inactive = $this->call('POST', '/api/ext/siro/store-locator/admin', array_merge($this->locationBody('Closed Store'), ['active' => false]), $this->headers($admin['access']));
        self::assertSame(200, $inactive['status']);

        $otherStore = $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody('Other Store'), $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);

        $public = $this->call('GET', '/api/ext/siro/store-locator/public', [], $this->headers());
        self::assertSame(200, $public['status']);
        self::assertIsArray($public['json']);
        self::assertCount(1, $public['json']['data']);
        $row = $public['json']['data'][0];
        self::assertSame('Active Store', $row['name']);
        self::assertSame('123 Nguyen Hue', $row['address']);
        self::assertSame('Ho Chi Minh City', $row['city']);
        self::assertSame('+84 28 1234 5678', $row['phone']);
        self::assertSame('Mon-Sat 09:00-21:00', $row['hours']);
        self::assertSame(10.7769, $row['latitude']);
        self::assertSame(106.7009, $row['longitude']);
        self::assertArrayNotHasKey('store_id', $row);
        self::assertArrayNotHasKey('active', $row);

        $otherPublic = $this->call('GET', '/api/ext/siro/store-locator/public', [], $this->headers(null, 'store-b', 'web'));
        self::assertSame(200, $otherPublic['status']);
        self::assertIsArray($otherPublic['json']);
        self::assertCount(1, $otherPublic['json']['data']);
        self::assertSame('Other Store', $otherPublic['json']['data'][0]['name']);

        $otherChannel = $this->call('GET', '/api/ext/siro/store-locator/public', [], $this->headers(null, 'default', 'mobile'));
        self::assertSame(200, $otherChannel['status']);
        self::assertIsArray($otherChannel['json']);
        self::assertCount(0, $otherChannel['json']['data']);
    }

    public function testAdminCrudIsScopedAndIdempotent(): void
    {
        $this->enableStoreLocator();
        $admin = $this->token('admin');
        $key = 'locator-create-' . bin2hex(random_bytes(6));

        $created = $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody(), $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $created['status']);
        self::assertIsArray($created['json']);
        $id = $created['json']['data']['id'];
        self::assertIsString($id);
        self::assertTrue($created['json']['data']['active']);

        $replay = $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody(), $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $replay['status']);
        self::assertIsArray($replay['json']);
        self::assertSame($id, $replay['json']['data']['id']);

        $otherStore = $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody('Store B'), $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);

        $list = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($admin['access']));
        self::assertSame(200, $list['status']);
        self::assertIsArray($list['json']);
        self::assertCount(1, $list['json']['data']);
        self::assertSame($id, $list['json']['data'][0]['id']);

        $otherList = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherList['status']);
        self::assertIsArray($otherList['json']);
        self::assertCount(1, $otherList['json']['data']);
        self::assertSame('Store B', $otherList['json']['data'][0]['name']);

        $update = ['name' => 'Renamed Store', 'active' => false, 'city' => 'Ha Noi'];
        $updated = $this->call('PUT', '/api/ext/siro/store-locator/admin/' . $id, $update, $this->headers($admin['access']));
        self::assertSame(200, $updated['status']);
        self::assertIsArray($updated['json']);
        self::assertSame('Renamed Store', $updated['json']['data']['name']);
        self::assertSame('Ha Noi', $updated['json']['data']['city']);
        self::assertSame('123 Nguyen Hue', $updated['json']['data']['address']);
        self::assertFalse($updated['json']['data']['active']);
        $updatedAt = $updated['json']['data']['updated_at'];
        self::assertIsString($updatedAt);

        $replayUpdate = $this->call('PUT', '/api/ext/siro/store-locator/admin/' . $id, $update, $this->headers($admin['access']));
        self::assertSame(200, $replayUpdate['status']);
        self::assertIsArray($replayUpdate['json']);
        self::assertSame($updatedAt, $replayUpdate['json']['data']['updated_at']);

        $public = $this->call('GET', '/api/ext/siro/store-locator/public', [], $this->headers());
        self::assertSame(200, $public['status']);
        self::assertIsArray($public['json']);
        self::assertCount(0, $public['json']['data']);

        $crossUpdate = $this->call('PUT', '/api/ext/siro/store-locator/admin/' . $id, ['name' => 'Hijacked'], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(404, $crossUpdate['status']);

        $crossDelete = $this->call('DELETE', '/api/ext/siro/store-locator/admin/' . $id, [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $crossDelete['status']);
        self::assertIsArray($crossDelete['json']);
        self::assertFalse($crossDelete['json']['data']['deleted']);

        $stillThere = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($admin['access']));
        self::assertCount(1, $stillThere['json']['data']);

        $deleted = $this->call('DELETE', '/api/ext/siro/store-locator/admin/' . $id, [], $this->headers($admin['access']));
        self::assertSame(200, $deleted['status']);
        self::assertIsArray($deleted['json']);
        self::assertTrue($deleted['json']['data']['deleted']);

        $replayDelete = $this->call('DELETE', '/api/ext/siro/store-locator/admin/' . $id, [], $this->headers($admin['access']));
        self::assertSame(200, $replayDelete['status']);
        self::assertIsArray($replayDelete['json']);
        self::assertFalse($replayDelete['json']['data']['deleted']);

        $empty = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($admin['access']));
        self::assertCount(0, $empty['json']['data']);
    }

    public function testInvalidLocationInputReturns422(): void
    {
        $this->enableStoreLocator();
        $admin = $this->token('admin');

        $cases = [
            ['name' => 'x'],
            ['name' => str_repeat('n', 121)],
            ['name' => '<script>alert(1)</script>'],
            ['address' => '1234'],
            ['address' => str_repeat('a', 301)],
            ['city' => str_repeat('c', 81)],
            ['phone' => str_repeat('9', 41)],
            ['hours' => str_repeat('h', 121)],
            ['latitude' => 91],
            ['latitude' => -91],
            ['longitude' => 181],
            ['longitude' => -181],
            ['latitude' => 'north'],
            ['active' => 'maybe'],
        ];
        foreach ($cases as $override) {
            $body = array_merge($this->locationBody('Invalid ' . bin2hex(random_bytes(3))), $override);
            $response = $this->call('POST', '/api/ext/siro/store-locator/admin', $body, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }

        $latOnly = $this->locationBody('Latitude only');
        unset($latOnly['longitude']);
        self::assertSame(422, $this->call('POST', '/api/ext/siro/store-locator/admin', $latOnly, $this->headers($admin['access']))['status']);

        $lngOnly = $this->locationBody('Longitude only');
        unset($lngOnly['latitude']);
        self::assertSame(422, $this->call('POST', '/api/ext/siro/store-locator/admin', $lngOnly, $this->headers($admin['access']))['status']);

        foreach (['name', 'address', 'city'] as $required) {
            $body = $this->locationBody('Missing ' . $required);
            unset($body[$required]);
            self::assertSame(422, $this->call('POST', '/api/ext/siro/store-locator/admin', $body, $this->headers($admin['access']))['status']);
        }

        $created = $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody(), $this->headers($admin['access']));
        self::assertSame(200, $created['status']);
        self::assertIsArray($created['json']);
        $id = $created['json']['data']['id'];
        self::assertIsString($id);

        $updateCases = [
            ['name' => 'x'],
            ['latitude' => null],
            ['longitude' => null],
            ['active' => 'maybe'],
        ];
        foreach ($updateCases as $override) {
            $response = $this->call('PUT', '/api/ext/siro/store-locator/admin/' . $id, $override, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for update ' . json_encode($override));
        }
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableStoreLocator();
        $admin = $this->token('admin');
        $this->call('POST', '/api/ext/siro/store-locator/admin', $this->locationBody(), $this->headers($admin['access']));

        self::assertSame(200, $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($admin['access']))['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(StoreLocatorExtension::id());
        $this->remountRoutes();

        self::assertSame(404, $this->call('GET', '/api/ext/siro/store-locator/admin', [], $this->headers($admin['access']))['status']);
        self::assertSame(404, $this->call('GET', '/api/ext/siro/store-locator/public', [], $this->headers())['status']);
        self::assertSame(200, $this->call('GET', '/health/live')['status']);

        $lifecycle->uninstall(StoreLocatorExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_store_locations'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $products = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['products'], $products);
    }

    public function testExtensionPackagePassesQualityGate(): void
    {
        $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-store-locator-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $source = $this->basePath() . '/app/Extensions/Siro/StoreLocator';
            $packed = (new ExtensionPackageInstaller($this->basePath()))->pack($source, $output);
            self::assertTrue($packed['ok']);
            self::assertFileExists($output);

            $report = (new ExtensionQualityGate($this->basePath()))->run($output);
            self::assertTrue($report['ok'], (string) json_encode($report));
            $checks = array_column($report['checks'], 'ok', 'name');
            self::assertTrue($checks['package']);
            self::assertTrue($checks['store-scope']);
            self::assertTrue($checks['route-auth']);
            self::assertTrue($checks['secret-scan']);
            self::assertTrue($checks['php-lint']);
        } finally {
            @unlink($output);
        }
    }
}
