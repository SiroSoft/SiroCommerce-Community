<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\ProductLabels\Extension as ProductLabelsExtension;
use App\Extensions\Siro\ProductLabels\ProductLabelsService;
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

final class ExtensionProductLabelsTest extends TestCase
{
    private Router $router;

    /** @var list<Router> Keep retired routers alive so spl_object_id is not reused by the loader. */
    private array $retiredRouters = [];

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(ProductLabelsExtension::class);
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
        $email = 'labels-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'labels', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
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
            'Idempotency-Key' => $idempotencyKey ?? ('labels-' . bin2hex(random_bytes(6))),
        ];
    }

    private function enableLabels(): void
    {
        $service = $this->service();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new ProductLabelsExtension())->manifest()));
        $service->enable(ProductLabelsExtension::id());
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
    private function ruleBody(string $key = 'sale-10', array $overrides = []): array
    {
        return array_merge([
            'rule_key' => $key,
            'text' => 'Sale',
            'kind' => 'sale',
            'conditions' => ['on_sale' => true],
            'priority' => 100,
            'active' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function createRule(array $headers, array $body): array
    {
        $response = $this->call('POST', '/api/ext/siro/product-labels/admin/rules', $body, $headers);
        self::assertContains($response['status'], [200, 201], json_encode($response['json']));
        self::assertIsArray($response['json']);
        return $response['json'];
    }

    private function seedProduct(string $id, string $slug, int $priceMinor, ?int $compareMinor, ?string $createdAt = null): void
    {
        Database::table('products')->insert([
            'id' => $id,
            'name' => 'Label Test ' . $slug,
            'slug' => $slug,
            'status' => 'active',
            'store_id' => 'default',
            'channel_id' => 'web',
            'locale' => 'en',
            'currency' => 'VND',
            'created_at' => $createdAt ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Database::table('product_variants')->insert([
            'id' => 'v-' . $id,
            'product_id' => $id,
            'sku' => 'SKU-' . strtoupper($id) . '-' . bin2hex(random_bytes(3)),
            'price_minor' => $priceMinor,
            'compare_price_minor' => $compareMinor,
            'currency' => 'VND',
            'status' => 'active',
        ]);
    }

    public function testManifestAndMigrationsDeclareScopedRules(): void
    {
        self::assertContains(ProductLabelsExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new ProductLabelsExtension())->manifest();
        self::assertSame('siro/product-labels', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['product-labels:read', 'product-labels:manage'], $manifest['capabilities']);
        self::assertSame(['product-labels:manage'], $manifest['permissions']);
        self::assertSame('migrations/20261126000011_create_ext_product_label_rules.php', $manifest['migrations'][0]);
        self::assertSame('migrations/20261126000012_create_ext_product_label_audit.php', $manifest['migrations'][1]);
        self::assertSame(['product-card', 'pdp.title'], $manifest['slots']['storefront']);
        self::assertSame('admin/stats', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('stats', $manifest['admin']['panels'][0]['type']);
        self::assertSame('admin/rules', $manifest['admin']['panels'][1]['endpoint']);
        self::assertSame('table', $manifest['admin']['panels'][1]['type']);

        $raw = file_get_contents($this->basePath() . '/app/Extensions/Siro/ProductLabels/manifest.json');
        self::assertIsString($raw);
        $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame('siro.extension/2', $package['schema'] ?? null);
        self::assertSame('siro/product-labels', $package['id'] ?? null);
        self::assertSame('module', $package['type'] ?? null);
        self::assertSame(['product-card', 'pdp.title'], $package['slots']['storefront'] ?? null);

        $this->enableLabels();
        $statement = Database::connection()->query(
            'SELECT id, store_id, channel_id, rule_key, text, kind, conditions_json, priority, active, idempotency_key, created_at, updated_at FROM ext_product_label_rules LIMIT 0'
        );
        self::assertNotFalse($statement);
        self::assertSame(12, $statement->columnCount());
        $audit = Database::connection()->query(
            'SELECT id, store_id, channel_id, actor, action, rule_id, rule_key, payload_json, created_at FROM ext_product_label_audit LIMIT 0'
        );
        self::assertNotFalse($audit);
        self::assertSame(9, $audit->columnCount());
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableLabels();
        $noAuth = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        self::assertSame(401, $noAuth['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
        self::assertIsArray($allowed['json']);
        self::assertSame([], $allowed['json']['data']['rules']);
        self::assertArrayHasKey('table', $allowed['json']['data']);

        $stats = $this->call('GET', '/api/ext/siro/product-labels/admin/stats', [], $this->headers($admin['access']));
        self::assertSame(200, $stats['status']);
        self::assertIsArray($stats['json']);
        self::assertSame(0, $stats['json']['data']['total']);
        self::assertSame(0, $stats['json']['data']['active']);
    }

    public function testRuleCrudScopedAndIdempotent(): void
    {
        $this->enableLabels();
        $admin = $this->token('admin');
        $key = 'labels-create-' . bin2hex(random_bytes(6));
        $body = $this->ruleBody();

        $first = $this->call('POST', '/api/ext/siro/product-labels/admin/rules', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertSame(201, $first['status'], json_encode($first['json']));
        self::assertIsArray($first['json']);
        $ruleId = $first['json']['data']['id'] ?? null;
        self::assertIsString($ruleId);
        self::assertSame('sale-10', $first['json']['data']['rule_key']);

        $replay = $this->call('POST', '/api/ext/siro/product-labels/admin/rules', $body, $this->headers($admin['access'], 'default', 'web', $key));
        self::assertContains($replay['status'], [200, 201], json_encode($replay['json']));
        self::assertIsArray($replay['json']);
        self::assertSame($ruleId, $replay['json']['data']['id']);

        $duplicateKey = $this->call('POST', '/api/ext/siro/product-labels/admin/rules', $this->ruleBody(), $this->headers($admin['access']));
        self::assertSame(422, $duplicateKey['status']);

        $otherStore = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(200, $otherStore['status']);
        self::assertIsArray($otherStore['json']);
        self::assertSame([], $otherStore['json']['data']['rules']);

        $updated = $this->call('PUT', '/api/ext/siro/product-labels/admin/rules/' . $ruleId, ['text' => 'Big Sale', 'priority' => 5], $this->headers($admin['access']));
        self::assertSame(200, $updated['status'], json_encode($updated['json']));
        self::assertIsArray($updated['json']);
        self::assertSame('Big Sale', $updated['json']['data']['text']);
        self::assertSame(5, $updated['json']['data']['priority']);

        $rename = $this->call('PUT', '/api/ext/siro/product-labels/admin/rules/' . $ruleId, ['rule_key' => 'other-key'], $this->headers($admin['access']));
        self::assertSame(422, $rename['status']);

        $missing = $this->call('PUT', '/api/ext/siro/product-labels/admin/rules/no-such-id', ['text' => 'X'], $this->headers($admin['access']));
        self::assertSame(404, $missing['status']);

        $shown = $this->call('GET', '/api/ext/siro/product-labels/admin/rules/' . $ruleId, [], $this->headers($admin['access']));
        self::assertSame(200, $shown['status']);
        self::assertIsArray($shown['json']);
        self::assertSame('Big Sale', $shown['json']['data']['text']);

        $auditRows = Database::connection()->query(
            "SELECT action FROM ext_product_label_audit WHERE store_id = 'default' AND channel_id = 'web' ORDER BY created_at ASC"
        );
        self::assertNotFalse($auditRows);
        $actions = $auditRows->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('rule.created', $actions);
        self::assertContains('rule.updated', $actions);

        $deleted = $this->call('DELETE', '/api/ext/siro/product-labels/admin/rules/' . $ruleId, [], $this->headers($admin['access']));
        self::assertSame(200, $deleted['status']);
        self::assertIsArray($deleted['json']);
        self::assertTrue($deleted['json']['data']['deleted']);

        $deletedAgain = $this->call('DELETE', '/api/ext/siro/product-labels/admin/rules/' . $ruleId, [], $this->headers($admin['access']));
        self::assertSame(200, $deletedAgain['status']);
        self::assertIsArray($deletedAgain['json']);
        self::assertFalse($deletedAgain['json']['data']['deleted']);

        $gone = $this->call('GET', '/api/ext/siro/product-labels/admin/rules/' . $ruleId, [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
    }

    public function testInvalidRulesReturn422(): void
    {
        $this->enableLabels();
        $admin = $this->token('admin');
        $cases = [
            ['kind' => 'flashy'],
            ['kind' => 'sale', 'text' => ''],
            ['kind' => 'sale', 'text' => str_repeat('x', 81)],
            ['kind' => 'sale', 'text' => 'Sale <b>now</b>'],
            ['kind' => 'sale', 'rule_key' => 'Bad Key!'],
            ['kind' => 'sale', 'priority' => -1],
            ['kind' => 'sale', 'priority' => 1001],
            ['kind' => 'sale', 'active' => 'yes'],
            ['kind' => 'sale', 'conditions' => ['nope' => true]],
            ['kind' => 'sale', 'conditions' => ['is_new_days' => 0]],
            ['kind' => 'sale', 'conditions' => ['discount_min_pct' => 101]],
            ['kind' => 'sale', 'conditions' => ['rating_min' => 6]],
        ];
        foreach ($cases as $override) {
            $body = $this->ruleBody('bad-' . bin2hex(random_bytes(3)), $override);
            $response = $this->call('POST', '/api/ext/siro/product-labels/admin/rules', $body, $this->headers($admin['access']));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($override));
        }
    }

    public function testMutationRequiresIdempotencyKey(): void
    {
        $this->enableLabels();
        $admin = $this->token('admin');
        $headers = $this->headers($admin['access']);
        unset($headers['Idempotency-Key']);
        $post = $this->call('POST', '/api/ext/siro/product-labels/admin/rules', $this->ruleBody(), $headers);
        self::assertSame(422, $post['status']);

        $created = $this->createRule($this->headers($admin['access']), $this->ruleBody());
        $ruleId = $created['data']['id'] ?? '';
        self::assertIsString($ruleId);
        $put = $this->call('PUT', '/api/ext/siro/product-labels/admin/rules/' . $ruleId, ['text' => 'No key'], $headers);
        self::assertSame(422, $put['status']);
    }

    public function testPublicLabelsEvaluateRules(): void
    {
        $this->enableLabels();
        $this->seedProduct('p-label-1', 'label-shirt', 80000, 100000);
        $admin = $this->token('admin');
        // Fresh headers per create: same Idempotency-Key replays by design.
        $this->createRule($this->headers($admin['access']), $this->ruleBody('sale-rule', ['text' => 'Sale', 'kind' => 'sale', 'conditions' => [], 'priority' => 100]));
        $this->createRule($this->headers($admin['access']), $this->ruleBody('new-rule', ['text' => 'New', 'kind' => 'new', 'conditions' => [], 'priority' => 90]));
        $this->createRule($this->headers($admin['access']), $this->ruleBody('pick-rule', ['text' => 'Staff pick', 'kind' => 'custom', 'conditions' => ['product_ids' => ['p-label-1']], 'priority' => 5]));
        $this->createRule($this->headers($admin['access']), $this->ruleBody('off-rule', ['text' => 'Hidden', 'kind' => 'custom', 'conditions' => [], 'priority' => 1, 'active' => false]));
        $this->createRule($this->headers($admin['access'], 'store-b', 'web'), $this->ruleBody('other-store', ['text' => 'Other store', 'kind' => 'custom', 'conditions' => [], 'priority' => 1]));

        $query = ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND', 'product_slug' => 'label-shirt'];
        $response = $this->call('GET', '/api/ext/siro/product-labels/public/labels', [], [], $query);
        self::assertSame(200, $response['status'], json_encode($response['json']));
        self::assertIsArray($response['json']);
        self::assertSame('p-label-1', $response['json']['data']['product_id']);
        $labels = $response['json']['data']['labels'] ?? null;
        self::assertIsArray($labels);
        // Priority order: Staff pick (5), New (90, implied is_new_days), Sale (100, implied on_sale).
        self::assertSame(['Staff pick', 'New', 'Sale'], array_column($labels, 'text'));
        self::assertSame(['custom', 'new', 'sale'], array_column($labels, 'kind'));

        $noSlug = $this->call('GET', '/api/ext/siro/product-labels/public/labels', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(422, $noSlug['status']);

        $unknown = $this->call('GET', '/api/ext/siro/product-labels/public/labels', [], [], array_merge($query, ['product_slug' => 'no-such-product']));
        self::assertSame(200, $unknown['status']);
        self::assertIsArray($unknown['json']);
        self::assertSame([], $unknown['json']['data']['labels']);
    }

    public function testPublicLabelsCapAtThreeAndSkipNonMatching(): void
    {
        $this->enableLabels();
        $this->seedProduct('p-label-2', 'label-old-shirt', 50000, null, date('Y-m-d H:i:s', time() - 90 * 86400));
        $admin = $this->token('admin');
        // Old full-price product: new/sale rules must not match; 4 custom
        // catch-alls compete, only the first 3 by priority render.
        // Fresh headers per create: same Idempotency-Key replays by design.
        for ($i = 1; $i <= 4; $i++) {
            $this->createRule($this->headers($admin['access']), $this->ruleBody('catch-' . $i, ['text' => 'Catch ' . $i, 'kind' => 'custom', 'conditions' => [], 'priority' => $i * 10]));
        }
        $this->createRule($this->headers($admin['access']), $this->ruleBody('new-rule', ['text' => 'New', 'kind' => 'new', 'conditions' => [], 'priority' => 1]));
        $this->createRule($this->headers($admin['access']), $this->ruleBody('sale-rule', ['text' => 'Sale', 'kind' => 'sale', 'conditions' => [], 'priority' => 2]));

        $query = ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND', 'product_slug' => 'label-old-shirt'];
        $response = $this->call('GET', '/api/ext/siro/product-labels/public/labels', [], [], $query);
        self::assertSame(200, $response['status'], json_encode($response['json']));
        self::assertIsArray($response['json']);
        self::assertSame(['Catch 1', 'Catch 2', 'Catch 3'], array_column($response['json']['data']['labels'], 'text'));
    }

    public function testScopeIsolationAtServiceLevel(): void
    {
        $this->enableLabels();
        $scopeA = new CommerceScope('store-a', 'web', 'en', 'VND');
        $scopeB = new CommerceScope('store-b', 'web', 'en', 'VND');
        $service = new ProductLabelsService();
        $rule = $service->create($scopeA, $this->ruleBody('scoped-rule'), null, 'admin@test.local');
        self::assertSame([], $service->list($scopeB));
        self::assertNull($service->find($scopeB, (string) $rule['id']));
        self::assertNotNull($service->find($scopeA, (string) $rule['id']));

        // Same rule_key works in a second store (unique key is scope-prefixed).
        $other = $service->create($scopeB, $this->ruleBody('scoped-rule'), null, 'admin@test.local');
        self::assertNotSame($rule['id'], $other['id']);

        // A row read under the wrong scope fails closed via assertScoped.
        $foreignRow = $rule;
        $this->expectException(CommerceScopeException::class);
        ExtensionScope::assertScoped($foreignRow, $scopeB);
    }

    public function testMismatchedScopeInWriteIsRejected(): void
    {
        $this->enableLabels();
        $service = new ProductLabelsService();
        $scope = new CommerceScope('store-a', 'web', 'en', 'VND');
        $this->expectException(CommerceScopeException::class);
        $service->create($scope, array_merge($this->ruleBody(), ['store_id' => 'store-b']));
    }

    public function testUpgradeKeepsEnabledStateAndVersion(): void
    {
        $this->enableLabels();
        $admin = $this->token('admin');
        $created = $this->createRule($this->headers($admin['access']), $this->ruleBody());
        self::assertIsString($created['data']['id'] ?? null);

        $manifestArray = (new ProductLabelsExtension())->manifest();
        $manifestArray['version'] = '1.1.0';
        $lifecycle = $this->service();
        $lifecycle->upgrade(ExtensionManifest::fromArray($manifestArray));

        $row = \App\Models\Extension::query()->where('id', ProductLabelsExtension::id())->first();
        self::assertNotNull($row);
        $data = $row->toArray();
        self::assertSame('enabled', $data['state']);
        self::assertSame('1.1.0', $data['version']);

        $ok = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);
        self::assertIsArray($ok['json']);
        self::assertSame('sale-10', $ok['json']['data']['rules'][0]['rule_key']);
    }

    public function testVersionMismatchBlocksInstall(): void
    {
        $lifecycle = $this->service();
        $badRequires = (new ProductLabelsExtension())->manifest();
        $badRequires['requires'] = ['core' => '^99.0', 'commerce' => '^0.1'];
        $errors = $lifecycle->validate(ExtensionManifest::fromArray($badRequires));
        self::assertNotSame([], $errors);
        $this->expectException(\InvalidArgumentException::class);
        $lifecycle->install(ExtensionManifest::fromArray($badRequires));
    }

    public function testEnableDoesNotBreakCoreProductFlow(): void
    {
        $this->enableLabels();
        $this->seedProduct('p-label-3', 'label-core-shirt', 60000, null);
        $response = $this->call('GET', '/api/products/label-core-shirt', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        self::assertSame(200, $response['status'], json_encode($response['json']));
        self::assertIsArray($response['json']);
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableLabels();
        $admin = $this->token('admin');
        $created = $this->createRule($this->headers($admin['access']), $this->ruleBody());
        self::assertIsString($created['data']['id'] ?? null);

        $ok = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], $this->headers($admin['access']));
        self::assertSame(200, $ok['status']);

        $lifecycle = $this->service();
        $lifecycle->disable(ProductLabelsExtension::id());
        $this->remountRoutes();
        $gone = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], $this->headers($admin['access']));
        self::assertSame(404, $gone['status']);
        $publicGone = $this->call('GET', '/api/ext/siro/product-labels/public/labels', [], [], ['store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND', 'product_slug' => 'x']);
        self::assertSame(404, $publicGone['status']);
        $health = $this->call('GET', '/health/live');
        self::assertSame(200, $health['status']);

        // Uninstall (keep data): tables + rows survive.
        $lifecycle->uninstall(ProductLabelsExtension::id(), false);
        $rules = Database::connection()->query("SELECT rule_key FROM ext_product_label_rules WHERE rule_key = 'sale-10'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['sale-10'], $rules);

        // Reinstall reuses the kept rule.
        $lifecycle->install(ExtensionManifest::fromArray((new ProductLabelsExtension())->manifest()));
        $lifecycle->enable(ProductLabelsExtension::id());
        $this->remountRoutes();
        $back = $this->call('GET', '/api/ext/siro/product-labels/admin/rules', [], $this->headers($admin['access']));
        self::assertSame(200, $back['status']);
        self::assertIsArray($back['json']);
        self::assertSame('sale-10', $back['json']['data']['rules'][0]['rule_key']);

        // Uninstall (remove data): both ext_* tables drop, core stays intact.
        $lifecycle->disable(ProductLabelsExtension::id());
        $lifecycle->uninstall(ProductLabelsExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ext_product_label_%'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $products = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['products'], $products);
    }
}
