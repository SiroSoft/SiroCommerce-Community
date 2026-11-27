<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\AbandonedCart\AbandonedCartService;
use App\Extensions\Siro\AbandonedCart\Extension as AbandonedCartExtension;
use App\Models\Extension;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Mail\Application\MailTemplateRegistry;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\Mailer;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Mail\MailProvider;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Batch 1 Wave 1 — Abandoned Cart free package (siro/abandoned-cart).
 * RULE-E10 test matrix rows 1-8 + consent gating + coupon + QIT.
 */
final class ExtensionAbandonedCartTest extends TestCase
{
    private Router $router;

    /** @var list<Router> Keep retired routers alive so spl_object_id is not reused by the loader. */
    private array $retiredRouters = [];

    /** @var list<array<string, mixed>> Captured fake-mail sends. */
    private array $sent = [];

    private ?string $packedZip = null;

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(AbandonedCartExtension::class);
        ExtensionRegistry::reset();
        Event::flush();
        MailTemplateRegistry::reset();
        Mailer::reset();
        $this->sent = [];
        $sent = &$this->sent;
        Mailer::useProvider(new class($sent) implements MailProvider {
            /** @var list<array<string, mixed>> */
            public array $sent;
            /** @param list<array<string, mixed>> $sent */
            public function __construct(array &$sent) { $this->sent = &$sent; }
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool { $this->sent[] = $mail; return true; }
        });
        $this->resetMountedRoutes();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        Event::flush();
        MailTemplateRegistry::reset();
        Mailer::reset();
        $this->resetMountedRoutes();
        if ($this->packedZip !== null && is_file($this->packedZip)) @unlink($this->packedZip);
        parent::tearDown();
    }

    private function resetMountedRoutes(): void
    {
        $property = (new \ReflectionClass(\App\Modules\Extension\Application\ExtensionRouteLoader::class))->getProperty('mounted');
        $property->setAccessible(true);
        $property->setValue(null, null);
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
        $email = 'cart-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'cart', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['access' => $tokens->access, 'role' => $role];
    }

    /** @return array<string, string> */
    private function headers(string $access, ?string $idempotencyKey = null): array
    {
        return [
            'Authorization' => 'Bearer ' . $access,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'web',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
            'Idempotency-Key' => $idempotencyKey ?? ('cart-' . bin2hex(random_bytes(6))),
        ];
    }

    private function enableFree(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new AbandonedCartExtension())->manifest()));
        $service->enable(AbandonedCartExtension::id());
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

    private function state(string $id): ?string
    {
        $row = Extension::find($id);
        return $row === null ? null : ($row->toArray()['state'] ?? null);
    }

    private function seedCustomer(string $id, string $email, bool $consented): void
    {
        $now = date('Y-m-d H:i:s');
        Database::table('customers')->insert(['id' => $id, 'email' => $email, 'name' => 'Lan Test', 'created_at' => $now]);
        Database::table('customer_notification_preferences')->insert([
            'customer_id' => $id, 'transactional_email' => 1, 'marketing_email' => $consented ? 1 : 0,
            'idempotency_key' => null, 'request_fingerprint' => null, 'updated_at' => $now,
        ]);
    }

    private function seedCart(string $id, string $customerId): void
    {
        $now = date('Y-m-d H:i:s');
        Database::table('carts')->insert([
            'id' => $id, 'customer_id' => $customerId, 'currency' => 'VND', 'state' => 'open',
            'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $totals */
    private function emitAbandoned(string $cartId, string $customerId, array $totals = []): void
    {
        Event::emit('cart.abandoned', [
            'cart_id' => $cartId,
            'customer_id' => $customerId,
            'totals_minor' => $totals === [] ? ['currency' => 'VND', 'total_minor' => 250000, 'lines' => [['name' => 'Silk dress', 'qty' => 1, 'unit_price_minor' => 250000]]] : $totals,
            'at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $rows = Database::select($sql, $params);
        return array_values(array_filter($rows, 'is_array'));
    }

    private function tableExists(string $table): bool
    {
        return $this->rows("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]) !== [];
    }

    // ------------------------------------------------------------ RULE-E10 #1

    public function testManifestValidAndBadManifestRejected(): void
    {
        self::assertContains(AbandonedCartExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new AbandonedCartExtension())->manifest();
        self::assertSame('siro/abandoned-cart', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['abandoned-cart:read', 'abandoned-cart:manage', 'coupon:create'], $manifest['capabilities']);
        self::assertSame(['abandoned-cart:manage', 'coupon:create'], $manifest['permissions']);
        self::assertSame('migrations/20261126000041_create_ext_abandoned_cart_snapshots.php', $manifest['migrations'][0]);
        self::assertSame('migrations/20261126000042_create_ext_abandoned_cart_deliveries.php', $manifest['migrations'][1]);
        self::assertSame('admin/overview', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('admin/snapshots', $manifest['admin']['panels'][1]['endpoint']);
        self::assertArrayNotHasKey('requires_extensions', $manifest);

        $raw = file_get_contents($this->basePath() . '/app/Extensions/Siro/AbandonedCart/manifest.json');
        self::assertIsString($raw);
        $json = json_decode($raw, true);
        self::assertIsArray($json);
        self::assertSame('siro.extension/2', $json['schema'] ?? null);
        self::assertSame('App\\Extensions\\Siro\\AbandonedCart\\Extension', $json['provider'] ?? null);
        self::assertSame([], $json['slots']['storefront'] ?? null);

        // Bad manifests are rejected: a missing id, and an admin panel whose
        // permission is not declared in the manifest permissions.
        // (Note: the manifest layer does NOT validate semver format — a
        // non-empty version string passes fromArray; the version-mismatch
        // check happens at install/enable, covered by the next test.)
        try {
            ExtensionManifest::fromArray([
                'id' => 'siro/abandoned-cart', 'version' => '1.0.0',
                'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
                'permissions' => ['abandoned-cart:manage'],
                'admin' => ['label' => 'Abandoned Cart', 'section' => 'Extensions', 'panels' => [
                    ['key' => 'overview', 'title' => 'Overview', 'type' => 'stats', 'endpoint' => 'admin/overview', 'permission' => 'abandoned-cart:read'],
                ]],
            ]);
            self::fail('Expected InvalidArgumentException for admin panel permission not in manifest permissions');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('permission', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        ExtensionManifest::fromArray([
            'version' => '1.0.0',
            'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
        ]);
    }

    public function testVersionMismatchBlocksEnable(): void
    {
        $service = $this->service();
        $service->discover();
        $this->expectException(\InvalidArgumentException::class);
        $service->install(ExtensionManifest::fromArray([
            'id' => 'siro/abandoned-cart', 'version' => '1.0.0',
            'requires' => ['core' => '^99.0', 'commerce' => '^0.1'],
            'capabilities' => [], 'permissions' => [],
        ]));
    }

    // ------------------------------------------------------------ RULE-E10 #2

    public function testInstallEnableDisableUninstallKeepDataReinstallReusesData(): void
    {
        $this->enableFree();
        self::assertSame('enabled', $this->state('siro/abandoned-cart'));
        self::assertTrue($this->tableExists('ext_abandoned_cart_snapshots'));
        self::assertTrue($this->tableExists('ext_abandoned_cart_deliveries'));

        $this->seedCustomer('cust-1', 'lan@test.local', true);
        $this->seedCart('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-1']));
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_deliveries WHERE cart_id = ?', ['cart-1']));
        self::assertCount(1, $this->sent);

        $service = $this->service();
        $service->disable('siro/abandoned-cart');
        self::assertSame('disabled', $this->state('siro/abandoned-cart'));
        self::assertTrue($this->tableExists('ext_abandoned_cart_snapshots'));
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-1']));

        $service->uninstall('siro/abandoned-cart', false);
        self::assertSame('disabled', $this->state('siro/abandoned-cart'));
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-1']));

        // Reinstall reuses the data; the listener works again.
        $service->install(ExtensionManifest::fromArray((new AbandonedCartExtension())->manifest()));
        $service->enable('siro/abandoned-cart');
        self::assertSame('enabled', $this->state('siro/abandoned-cart'));
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-1']));
        $this->seedCart('cart-1b', 'cust-1');
        $this->emitAbandoned('cart-1b', 'cust-1');
        self::assertCount(2, $this->sent);
    }

    // ------------------------------------------------------------ RULE-E10 #3

    public function testUninstallRemoveDataDropsTablesKeepsCoreIntact(): void
    {
        $this->enableFree();
        $this->seedCustomer('cust-1', 'lan@test.local', true);
        $this->seedCart('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-1']));

        $service = $this->service();
        $service->uninstall('siro/abandoned-cart', true);
        self::assertNull($this->state('siro/abandoned-cart'));
        self::assertFalse($this->tableExists('ext_abandoned_cart_snapshots'));
        self::assertFalse($this->tableExists('ext_abandoned_cart_deliveries'));
        self::assertTrue($this->tableExists('carts'));
        self::assertTrue($this->tableExists('customers'));
        self::assertCount(1, $this->rows('SELECT * FROM carts WHERE id = ?', ['cart-1']));
    }

    // ------------------------------------------------------------ RULE-E10 #4

    public function testRoutesAuthMatrix(): void
    {
        $this->enableFree();
        $admin = $this->token('admin');
        $viewer = $this->token('viewer');

        $noAuth = $this->call('GET', '/api/ext/siro/abandoned-cart/admin/overview');
        self::assertSame(401, $noAuth['status'], (string) json_encode($noAuth['json']));

        $forbidden = $this->call('GET', '/api/ext/siro/abandoned-cart/admin/overview', [], $this->headers($viewer['access']));
        self::assertSame(403, $forbidden['status'], (string) json_encode($forbidden['json']));

        $ok = $this->call('GET', '/api/ext/siro/abandoned-cart/admin/overview', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status'], (string) json_encode($ok['json']));
        self::assertIsArray($ok['json']['data']['stats'] ?? null);
    }

    // ------------------------------------------------------------ RULE-E10 #5

    public function testIdempotencyKeyRequiredAndReplaySafe(): void
    {
        $this->enableFree();
        $admin = $this->token('admin');

        $noKey = $this->call('POST', '/api/ext/siro/abandoned-cart/coupons', ['code' => 'REPLAY-1'], [
            'Authorization' => 'Bearer ' . $admin['access'],
            'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND',
        ]);
        self::assertSame(422, $noKey['status'], (string) json_encode($noKey['json']));

        $first = $this->call('POST', '/api/ext/siro/abandoned-cart/coupons', ['code' => 'REPLAY-1'], $this->headers($admin['access'], 'key-replay-1'));
        self::assertContains($first['status'], [200, 201], (string) json_encode($first['json']));
        $second = $this->call('POST', '/api/ext/siro/abandoned-cart/coupons', ['code' => 'REPLAY-1'], $this->headers($admin['access'], 'key-replay-2'));
        self::assertContains($second['status'], [200, 201]);
        self::assertSame($first['json']['data']['id'] ?? null, $second['json']['data']['id'] ?? null);
        self::assertCount(1, $this->rows('SELECT * FROM coupons WHERE code = ?', ['REPLAY-1']));

        // Re-emitted event: one delivery, one mail — never a duplicate.
        $this->seedCustomer('cust-1', 'lan@test.local', true);
        $this->seedCart('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');
        self::assertCount(1, $this->sent);
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_deliveries WHERE cart_id = ?', ['cart-1']));
    }

    // ------------------------------------------------------------ RULE-E10 #6

    public function testListenerFailureDoesNotBreakCoreFlow(): void
    {
        $this->enableFree();
        // Garbage payloads must not throw.
        Event::emit('cart.abandoned', ['customer_id' => 'nobody']);
        Event::emit('cart.abandoned', 'not-an-array');
        self::assertSame([], $this->sent);

        // A throwing mail provider must not break the listener either:
        // snapshot is saved, no delivery is recorded, core flow continues.
        Mailer::useProvider(new class implements MailProvider {
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool { throw new \RuntimeException('smtp down'); }
        });
        $this->seedCustomer('cust-1', 'lan@test.local', true);
        $this->seedCart('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-1']));
        self::assertSame([], $this->rows('SELECT * FROM ext_abandoned_cart_deliveries WHERE cart_id = ?', ['cart-1']));
        self::assertSame([], $this->sent);
    }

    // ------------------------------------------------------------ RULE-E10 #7

    public function testUpgradeKeepsStateEnabled(): void
    {
        $this->enableFree();
        $service = $this->service();
        $service->disable('siro/abandoned-cart');
        self::assertSame('disabled', $this->state('siro/abandoned-cart'));
        $service->upgrade(ExtensionManifest::fromArray((new AbandonedCartExtension())->manifest()));
        self::assertSame('enabled', $this->state('siro/abandoned-cart'));
    }

    // ------------------------------------------------------------ consent + mail

    public function testNoConsentSendsNothingButKeepsSnapshot(): void
    {
        $this->enableFree();
        $this->seedCustomer('cust-1', 'lan@test.local', false);
        $this->seedCart('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-1']));
        self::assertSame([], $this->sent);
        self::assertSame([], $this->rows('SELECT * FROM ext_abandoned_cart_deliveries WHERE cart_id = ?', ['cart-1']));
    }

    public function testConsentedCartSendsOneMailWithRecoveryCoupon(): void
    {
        $this->enableFree();
        $this->seedCustomer('cust-1', 'lan@test.local', true);
        $this->seedCart('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');
        self::assertCount(1, $this->sent);
        $mail = $this->sent[0];
        self::assertSame('lan@test.local', $mail['to'] ?? null);
        self::assertSame('Your cart is waiting for you', $mail['subject'] ?? null);
        $body = (string) ($mail['body'] ?? $mail['text'] ?? '');
        self::assertStringContainsString('Hi Lan Test', $body);
        self::assertStringContainsString('Silk dress', $body);
        self::assertStringContainsString('/cart', $body);
        $code = AbandonedCartService::recoveryCouponCode('cart-1');
        self::assertStringContainsString($code, $body);
        $coupons = $this->rows('SELECT * FROM coupons WHERE code = ?', [$code]);
        self::assertCount(1, $coupons);
        $rules = json_decode((string) ($coupons[0]['rules'] ?? '{}'), true);
        self::assertIsArray($rules);
        self::assertSame('percent', $rules['type'] ?? null);
        self::assertSame(10, $rules['value'] ?? null);
        self::assertSame(1, $rules['usage_limit'] ?? null);
    }

    public function testAnonymousCartKeepsSnapshotSendsNothing(): void
    {
        $this->enableFree();
        $this->seedCart('cart-anon', 'cust-ghost');
        Event::emit('cart.abandoned', [
            'cart_id' => 'cart-anon', 'customer_id' => null,
            'totals_minor' => ['currency' => 'VND', 'total_minor' => 1000, 'lines' => []],
            'at' => date('Y-m-d H:i:s'),
        ]);
        self::assertCount(1, $this->rows('SELECT * FROM ext_abandoned_cart_snapshots WHERE cart_id = ?', ['cart-anon']));
        self::assertSame([], $this->sent);
    }

    // ------------------------------------------------------------ coupons route

    public function testCreateCouponValidation(): void
    {
        $this->enableFree();
        $admin = $this->token('admin');
        $badCode = $this->call('POST', '/api/ext/siro/abandoned-cart/coupons', ['code' => 'x'], $this->headers($admin['access']));
        self::assertSame(422, $badCode['status']);
        $badRules = $this->call('POST', '/api/ext/siro/abandoned-cart/coupons', ['code' => 'GOOD-1', 'rules' => '[1,2]'], $this->headers($admin['access']));
        self::assertSame(422, $badRules['status']);
        $ok = $this->call('POST', '/api/ext/siro/abandoned-cart/coupons', ['code' => 'GOOD-1', 'rules' => '{"type":"percent","value":15}'], $this->headers($admin['access']));
        self::assertContains($ok['status'], [200, 201]);
        self::assertSame('GOOD-1', $ok['json']['data']['code'] ?? null);
        $auto = $this->call('POST', '/api/ext/siro/abandoned-cart/coupons', ['cart_id' => 'cart-9'], $this->headers($admin['access']));
        self::assertContains($auto['status'], [200, 201]);
        self::assertSame(AbandonedCartService::recoveryCouponCode('cart-9'), $auto['json']['data']['code'] ?? null);
    }

    // ------------------------------------------------------------ admin panels

    public function testAdminPanelsShowSnapshotAndDelivery(): void
    {
        $this->enableFree();
        $admin = $this->token('admin');
        $this->seedCustomer('cust-1', 'lan@test.local', true);
        $this->seedCart('cart-1', 'cust-1');
        $this->emitAbandoned('cart-1', 'cust-1');

        $overview = $this->call('GET', '/api/ext/siro/abandoned-cart/admin/overview', [], $this->headers($admin['access']));
        self::assertSame(200, $overview['status']);
        $stats = $overview['json']['data']['stats'] ?? [];
        self::assertIsArray($stats);
        $byLabel = [];
        foreach ($stats as $stat) {
            if (is_array($stat)) $byLabel[(string) ($stat['label'] ?? '')] = $stat['value'] ?? null;
        }
        self::assertSame(1, $byLabel['Abandoned carts'] ?? null);
        self::assertSame(1, $byLabel['Reminder emails sent'] ?? null);

        $snapshots = $this->call('GET', '/api/ext/siro/abandoned-cart/admin/snapshots', [], $this->headers($admin['access']));
        self::assertSame(200, $snapshots['status']);
        $rows = $snapshots['json']['data']['table']['rows'] ?? [];
        self::assertIsArray($rows);
        self::assertSame('cart-1', $rows[0]['cart_id'] ?? null);
        self::assertSame('250000', $rows[0]['total_minor'] ?? null);

        $deliveries = $this->call('GET', '/api/ext/siro/abandoned-cart/admin/deliveries', [], $this->headers($admin['access']));
        self::assertSame(200, $deliveries['status']);
        self::assertSame('cart-1', ($deliveries['json']['data']['table']['rows'][0]['cart_id'] ?? null));
    }

    // ------------------------------------------------------------ QIT

    public function testPackVerifyQitPass(): void
    {
        $dir = $this->basePath() . '/app/Extensions/Siro/AbandonedCart';
        $installer = new ExtensionPackageInstaller($this->basePath());
        // Explicit temp output: the default pack path is <source>/dist/…
        // inside the repo — never write the zip into the worktree.
        $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-abandoned-cart-' . bin2hex(random_bytes(4)) . '.zip';
        $packed = $installer->pack($dir, $out);
        self::assertTrue($packed['ok'] ?? false, (string) json_encode($packed));
        $this->packedZip = is_string($packed['path'] ?? null) ? (string) $packed['path'] : null;
        self::assertNotNull($this->packedZip);
        $report = $installer->verify($this->packedZip);
        self::assertTrue($report['ok'] === true, implode('; ', $report['errors'] ?? []));
        $qit = (new ExtensionQualityGate($this->basePath()))->run($this->packedZip);
        self::assertTrue($qit['ok'], (string) json_encode($qit));
        $checks = array_column($qit['checks'], 'ok', 'name');
        foreach (['package', 'signature', 'store-scope', 'route-auth', 'secret-scan', 'php-lint'] as $name) {
            self::assertTrue($checks[$name] ?? false, $name . ' check must pass');
        }
    }
}
