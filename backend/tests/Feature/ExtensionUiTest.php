<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Extensions\Siro\StoreLocator\Extension as StoreLocatorExtension;
use App\Extension\ExtensionRegistry;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Extension admin UI (spec EXTENSION_UI_SPEC): manifest admin block
 * validation, ui nav endpoint (enabled-only), and a free extension panel.
 */
final class ExtensionUiTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
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
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array<string, string> */
    private function admin(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'admin', 'email' => 'extui-admin@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('extui-admin@shop.test', 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    private function enableStoreLocator(): void
    {
        $service = new ExtensionLifecycleService(ExtensionDiscovery::classes(dirname(__DIR__, 2)));
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new StoreLocatorExtension())->manifest()));
        $service->enable('siro/store-locator');
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    public function testManifestAdminBlockValidation(): void
    {
        $base = ['id' => 'v/x', 'version' => '1.0.0', 'requires' => [], 'capabilities' => [], 'permissions' => ['x:manage']];
        self::assertNull(ExtensionManifest::fromArray($base)->adminUi());
        $ui = ExtensionManifest::fromArray($base + ['admin' => ['label' => 'Dex', 'panels' => [['key' => 'p', 'title' => 'Panel', 'type' => 'stats', 'endpoint' => 'admin/p', 'permission' => 'x:manage']]]])->adminUi();
        self::assertNotNull($ui);
        self::assertSame('/extensions/v/x', $ui['href']);
        try {
            ExtensionManifest::fromArray($base + ['admin' => ['label' => 'Dex', 'panels' => [['key' => 'p', 'title' => 'Panel', 'type' => 'stats', 'endpoint' => 'admin/p', 'permission' => 'nope']]]]);
            self::fail('undeclared panel permission should throw');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            ExtensionManifest::fromArray($base + ['admin' => ['label' => 'Dex', 'panels' => [['key' => 'p', 'title' => 'Panel', 'type' => 'table', 'endpoint' => 'https://evil.test/x', 'permission' => 'x:manage']]]]);
            self::fail('absolute panel endpoint should throw');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            ExtensionManifest::fromArray($base + ['admin' => ['label' => 'Dex', 'panels' => [['key' => 'p', 'title' => 'Panel', 'type' => 'chart', 'endpoint' => 'x', 'permission' => 'x:manage']]]]);
            self::fail('unknown panel type should throw');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testUiEndpointListsEnabledExtensionsOnly(): void
    {
        $admin = $this->admin();
        $empty = $this->call('GET', '/api/admin/extensions/ui', [], $admin);
        $this->assertSame(200, $empty['status']);
        $this->assertSame([], $empty['json']['data']);

        $this->enableStoreLocator();
        $ui = $this->call('GET', '/api/admin/extensions/ui', [], $admin);
        $this->assertSame(200, $ui['status']);
        $this->assertCount(1, $ui['json']['data']);
        $entry = $ui['json']['data'][0];
        $this->assertSame('siro/store-locator', $entry['id']);
        $this->assertSame('Store locator', $entry['label']);
        $this->assertSame('Commerce', $entry['section']);
        $this->assertSame('/extensions/siro/store-locator', $entry['href']);
        $this->assertCount(1, $entry['panels']);

        $list = $this->call('GET', '/api/admin/extensions', [], $admin);
        $this->assertSame(200, $list['status']);
        foreach ($list['json']['data'] as $row) {
            if (($row['id'] ?? null) === 'siro/store-locator') {
                $this->assertSame('enabled', $row['state']);
                $this->assertSame('Store locator', $row['admin_ui']['label']);
            }
        }

        $panel = $this->call('GET', '/api/ext/siro/store-locator/admin', [], $admin);
        $this->assertSame(200, $panel['status']);
        $this->assertIsArray($panel['json']['data']);
    }
}
