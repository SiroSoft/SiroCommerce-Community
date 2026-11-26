<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\ContactWidget\Extension as ContactWidgetExtension;
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
 * siro/contact-widget: scoped settings, public storefront payload, auth matrix,
 * validation and QIT-lite packaging.
 */
final class ExtensionContactWidgetTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        // Loads Extension.php, which requires the self-contained src/ classes.
        class_exists(ContactWidgetExtension::class);
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

    /** @return array{status: int, json: mixed} */
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
        $email = 'cw-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'cw', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
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
            'Idempotency-Key' => $idempotencyKey ?? ('cw-' . bin2hex(random_bytes(6))),
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

    private function enableContactWidget(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new ContactWidgetExtension())->manifest()));
        $service->enable(ContactWidgetExtension::id());
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
    private function settingsBody(): array
    {
        return [
            'enabled' => true,
            'zalo_url' => 'https://zalo.me/123456789',
            'whatsapp_url' => 'https://wa.me/84123456789',
            'email' => 'support@example.com',
            'label' => 'Need help?',
        ];
    }

    public function testManifestAndDiscoveryDeclareContactWidget(): void
    {
        self::assertContains(ContactWidgetExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new ContactWidgetExtension())->manifest();
        self::assertSame('siro/contact-widget', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['contact-widget:read', 'contact-widget:manage'], $manifest['capabilities']);
        self::assertSame(['contact-widget:manage'], $manifest['permissions']);
        self::assertSame('migrations/20261123000003_create_ext_contact_widget.php', $manifest['migrations'][0]);
        self::assertContains('footer', $manifest['slots']['storefront']);
        self::assertSame('admin/settings', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('contact-widget:manage', $manifest['admin']['panels'][0]['permission']);
    }

    public function testRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableContactWidget();

        $noAuth = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->scopeHeaders());
        self::assertSame(401, $noAuth['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $writeDenied = $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', $this->settingsBody(), $this->headers($viewer['access']));
        self::assertSame(403, $writeDenied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
    }

    public function testSettingsSaveGetIsIdempotentAndScoped(): void
    {
        $this->enableContactWidget();
        $admin = $this->token('admin');
        $key = 'cw-settings-' . bin2hex(random_bytes(6));

        $first = $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', $this->settingsBody(), $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $first['status']);
        self::assertIsArray($first['json']);
        self::assertTrue($first['json']['data']['enabled']);
        self::assertSame('https://zalo.me/123456789', $first['json']['data']['zalo_url']);
        self::assertSame('Need help?', $first['json']['data']['label']);

        $replay = $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', $this->settingsBody(), $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $replay['status']);
        self::assertSame($first['json']['data']['id'], $replay['json']['data']['id']);

        $same = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $same['status']);
        self::assertIsArray($same['json']);
        self::assertSame('Need help?', $same['json']['data']['label']);
        self::assertSame('support@example.com', $same['json']['data']['email']);
        self::assertArrayHasKey('stats', $same['json']['data']);

        // Scope isolation: another store/channel sees defaults, not this row.
        $otherStore = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertFalse($otherStore['json']['data']['enabled']);
        self::assertSame('', $otherStore['json']['data']['zalo_url']);

        $otherChannel = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($admin['access'], 'default', 'mobile'));
        self::assertSame(200, $otherChannel['status']);
        self::assertIsArray($otherChannel['json']);
        self::assertFalse($otherChannel['json']['data']['enabled']);

        // Saving another scope never touches the original row.
        $otherSave = $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', ['enabled' => true, 'label' => 'Store B'], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherSave['status']);
        $original = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($admin['access']));
        self::assertIsArray($original['json']);
        self::assertSame('Need help?', $original['json']['data']['label']);
    }

    public function testPublicPayloadActiveAndDisabled(): void
    {
        $this->enableContactWidget();
        $admin = $this->token('admin');

        $inactive = $this->call('GET', '/api/ext/siro/contact-widget/public', [], $this->scopeHeaders());
        self::assertSame(200, $inactive['status']);
        self::assertIsArray($inactive['json']);
        self::assertFalse($inactive['json']['data']['enabled']);
        self::assertNull($inactive['json']['data']['links']['zalo']);

        $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', $this->settingsBody(), $this->headers($admin['access']));

        $active = $this->call('GET', '/api/ext/siro/contact-widget/public', [], $this->scopeHeaders());
        self::assertSame(200, $active['status']);
        self::assertIsArray($active['json']);
        self::assertTrue($active['json']['data']['enabled']);
        self::assertSame('https://zalo.me/123456789', $active['json']['data']['links']['zalo']);
        self::assertSame('https://wa.me/84123456789', $active['json']['data']['links']['whatsapp']);
        self::assertSame('support@example.com', $active['json']['data']['links']['email']);
        self::assertSame('Need help?', $active['json']['data']['label']);

        $otherStore = $this->call('GET', '/api/ext/siro/contact-widget/public', [], $this->scopeHeaders('store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertFalse($otherStore['json']['data']['enabled']);

        $disable = $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', ['enabled' => false], $this->headers($admin['access']));
        self::assertSame(200, $disable['status']);
        $disabled = $this->call('GET', '/api/ext/siro/contact-widget/public', [], $this->scopeHeaders());
        self::assertSame(200, $disabled['status']);
        self::assertIsArray($disabled['json']);
        self::assertFalse($disabled['json']['data']['enabled']);
        self::assertNull($disabled['json']['data']['links']['email']);
    }

    public function testInvalidSettingsReturn422(): void
    {
        $this->enableContactWidget();
        $admin = $this->token('admin');
        $cases = [
            ['zalo_url' => 'http://zalo.me/123'],
            ['zalo_url' => 'javascript:alert(1)'],
            ['whatsapp_url' => 'ftp://wa.me/123'],
            ['whatsapp_url' => 'not-a-url'],
            ['email' => 'not-an-email'],
            ['label' => ''],
            ['label' => str_repeat('x', 41)],
            ['enabled' => 'maybe'],
            ['zalo_url' => 123],
            ['email' => 123],
        ];
        foreach ($cases as $override) {
            $body = array_merge($this->settingsBody(), $override);
            $response = $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', $body, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }
    }

    public function testPackageAndQitLitePass(): void
    {
        $dir = $this->basePath() . '/app/Extensions/Siro/ContactWidget';
        $zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contact-widget-' . bin2hex(random_bytes(4)) . '.zip';
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
        $this->enableContactWidget();
        $admin = $this->token('admin');
        $this->call('PUT', '/api/ext/siro/contact-widget/admin/settings', $this->settingsBody(), $this->headers($admin['access']));

        $ok = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(ContactWidgetExtension::id());
        $this->remountRoutes();
        $gone = $this->call('GET', '/api/ext/siro/contact-widget/admin/settings', [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
        $health = $this->call('GET', '/health/live');
        self::assertSame(200, $health['status']);

        $lifecycle->uninstall(ContactWidgetExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_contact_widget'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $orders = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='orders'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['orders'], $orders);
    }
}
