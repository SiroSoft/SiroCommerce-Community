<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\ShippingFeeRules\Extension as ShippingFeeRulesExtension;
use App\Extensions\Siro\ShippingFeeRules\ShippingFeeRuleService;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Shipping\Application\ShippingFeeAdjusterRegistry;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ExtensionShippingFeeRulesTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        // Loads Extension.php, which requires the self-contained src/ classes.
        class_exists(ShippingFeeRulesExtension::class);
        ExtensionRegistry::reset();
        ShippingFeeAdjusterRegistry::reset();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        ShippingFeeAdjusterRegistry::reset();
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
        $email = 'ship-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'ship', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
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
            'Idempotency-Key' => $idempotencyKey ?? ('ship-' . bin2hex(random_bytes(6))),
        ];
    }

    private function enableShippingFeeRules(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(\App\Modules\Extension\Domain\ExtensionManifest::fromArray((new ShippingFeeRulesExtension())->manifest()));
        $service->enable(ShippingFeeRulesExtension::id());
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
    private function ruleBody(string $name = 'COD standard'): array
    {
        return [
            'name' => $name,
            'method' => 'cod',
            'min_subtotal_minor' => 0,
            'fee_minor' => 30000,
            'free_over_minor' => 500000,
            'active' => 1,
            'priority' => 100,
        ];
    }

    public function testManifestAndDiscoveryDeclareScopedShippingFeeRules(): void
    {
        self::assertContains(ShippingFeeRulesExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new ShippingFeeRulesExtension())->manifest();
        self::assertSame('siro/shipping-fee-rules', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['shipping-fee:read', 'shipping-fee:manage'], $manifest['capabilities']);
        self::assertSame(['shipping-fee:manage'], $manifest['permissions']);
        self::assertSame('migrations/20261122000001_create_ext_shipping_fee_rules.php', $manifest['migrations'][0]);
        self::assertSame('admin/overview', $manifest['admin']['panels'][0]['endpoint']);
    }

    public function testRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableShippingFeeRules();
        $noAuth = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        self::assertSame(401, $noAuth['status']);
        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);
        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
    }

    public function testCreateRuleIsIdempotentAndScoped(): void
    {
        $this->enableShippingFeeRules();
        $admin = $this->token('admin');
        $key = 'ship-rule-' . bin2hex(random_bytes(6));
        $body = $this->ruleBody();

        $first = $this->call('POST', '/api/ext/siro/shipping-fee-rules/rules', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $first['status']);
        $replay = $this->call('POST', '/api/ext/siro/shipping-fee-rules/rules', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(200, $replay['status']);
        self::assertIsArray($first['json']);
        self::assertIsArray($replay['json']);
        self::assertSame($first['json']['data']['id'], $replay['json']['data']['id']);

        $same = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], $this->headers($admin['access']));
        self::assertSame(200, $same['status']);
        self::assertIsArray($same['json']);
        self::assertCount(1, $same['json']['data']);

        $otherStore = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertCount(0, $otherStore['json']['data']);

        $otherChannel = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], $this->headers($admin['access'], 'default', 'mobile'));
        self::assertSame(200, $otherChannel['status']);
        self::assertIsArray($otherChannel['json']);
        self::assertCount(0, $otherChannel['json']['data']);

        $overview = $this->call('GET', '/api/ext/siro/shipping-fee-rules/admin/overview', [], $this->headers($admin['access']));
        self::assertSame(200, $overview['status']);
        self::assertIsArray($overview['json']);
        self::assertArrayHasKey('stats', $overview['json']['data']);
        self::assertArrayHasKey('table', $overview['json']['data']);
        self::assertSame('1', $overview['json']['data']['stats'][0]['value']);
    }

    public function testFeeForUsesHighestPriorityAndFreeThreshold(): void
    {
        $this->enableShippingFeeRules();
        $service = new ShippingFeeRuleService();
        $scope = new CommerceScope('store-a', 'web', 'en', 'VND');

        $service->create($scope, ['name' => 'Standard base', 'method' => 'standard', 'min_subtotal_minor' => 0, 'fee_minor' => 20000, 'free_over_minor' => 0, 'priority' => 10]);
        $service->create($scope, ['name' => 'Standard promo', 'method' => 'standard', 'min_subtotal_minor' => 0, 'fee_minor' => 30000, 'free_over_minor' => 500000, 'priority' => 100]);
        $service->create($scope, ['name' => 'COD flat', 'method' => 'cod', 'min_subtotal_minor' => 0, 'fee_minor' => 15000, 'free_over_minor' => 0, 'priority' => 50]);
        $service->create($scope, ['name' => 'Express flat', 'method' => 'express', 'min_subtotal_minor' => 0, 'fee_minor' => 50000, 'free_over_minor' => 1000000, 'priority' => 100]);

        self::assertSame(30000, $service->feeFor($scope, 100000, 'standard'));
        self::assertSame(0, $service->feeFor($scope, 500000, 'standard'));
        self::assertSame(15000, $service->feeFor($scope, 900000, 'cod'));
        self::assertSame(50000, $service->feeFor($scope, 999999, 'express'));
        self::assertSame(0, $service->feeFor($scope, 1000000, 'express'));

        $otherScope = new CommerceScope('store-b', 'web', 'en', 'VND');
        self::assertSame(0, $service->feeFor($otherScope, 100000, 'standard'));
    }

    public function testInvalidRuleInputReturns422(): void
    {
        $this->enableShippingFeeRules();
        $admin = $this->token('admin');
        $cases = [
            ['method' => 'drone'],
            ['fee_minor' => -1],
            ['name' => 'x'],
            ['name' => str_repeat('n', 121)],
            ['priority' => 1001],
            ['active' => 2],
            ['fee_minor' => null],
        ];
        foreach ($cases as $override) {
            $body = array_merge($this->ruleBody('Invalid ' . bin2hex(random_bytes(3))), $override);
            $response = $this->call('POST', '/api/ext/siro/shipping-fee-rules/rules', $body, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableShippingFeeRules();
        $admin = $this->token('admin');
        $service = new ShippingFeeRuleService();
        $scope = new CommerceScope('default', 'web', 'en', 'VND');
        $service->create($scope, $this->ruleBody());

        $ok = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(ShippingFeeRulesExtension::id());
        $this->remountRoutes();
        $gone = $this->call('GET', '/api/ext/siro/shipping-fee-rules/rules', [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
        $health = $this->call('GET', '/health/live');
        self::assertSame(200, $health['status']);

        $lifecycle->uninstall(ShippingFeeRulesExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_shipping_fee_rules'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $orders = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='orders'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['orders'], $orders);
    }
}
