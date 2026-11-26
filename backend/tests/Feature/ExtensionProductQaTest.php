<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\ProductQa\Extension as ProductQaExtension;
use App\Extensions\Siro\ProductQa\ProductQaService;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ExtensionProductQaTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(ProductQaExtension::class);
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
        $email = 'qa-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'qa', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
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
            'Idempotency-Key' => $key ?? ('qa-' . bin2hex(random_bytes(6))),
        ];
        if ($access !== null && $access !== '') {
            $headers['Authorization'] = 'Bearer ' . $access;
        }
        return $headers;
    }

    private function enableProductQa(): void
    {
        $service = $this->lifecycle();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new ProductQaExtension())->manifest()));
        $service->enable(ProductQaExtension::id());
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
    private function questionBody(string $productId = 'p-qa-1', string $name = 'Buyer One', string $question = 'Is this machine washable?'): array
    {
        return [
            'product_id' => $productId,
            'product_slug' => 'linen-camp-shirt',
            'customer_name' => $name,
            'question' => $question,
        ];
    }

    /** @param array<string, mixed> $row */
    private function rowId(array $row): string
    {
        $id = $row['id'] ?? null;
        self::assertIsString($id);
        return $id;
    }

    /** @return array<string, mixed> */
    private function createApproved(ProductQaService $service, CommerceScope $scope, string $productId, string $question, string $answer): array
    {
        $row = $service->submit($scope, $this->questionBody($productId, 'Buyer', $question), 'seed-' . bin2hex(random_bytes(6)));
        $updated = $service->adminUpdate($scope, $this->rowId($row), ['state' => 'approved', 'answer' => $answer]);
        self::assertIsArray($updated);
        return $updated;
    }

    public function testManifestAndDiscoveryDeclareScopedProductQa(): void
    {
        self::assertContains(ProductQaExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new ProductQaExtension())->manifest();

        self::assertSame('siro/product-qa', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['product-qa:read', 'product-qa:submit', 'product-qa:manage'], $manifest['capabilities']);
        self::assertSame(['product-qa:manage'], $manifest['permissions']);
        self::assertSame(['pdp'], $manifest['slots']['storefront']);
        self::assertSame('migrations/20261123000002_create_ext_product_qa.php', $manifest['migrations'][0]);
        self::assertSame('admin', $manifest['admin']['panels'][0]['endpoint']);
    }

    public function testPublicListReturnsOnlyApprovedQuestionsForTheScope(): void
    {
        $this->enableProductQa();
        $service = new ProductQaService();
        $scope = new CommerceScope('default', 'web', 'en', 'VND');
        $otherStore = new CommerceScope('store-b', 'web', 'en', 'VND');

        $this->createApproved($service, $scope, 'p-qa-1', 'Does it shrink after washing?', 'Wash cold; it keeps its shape.');
        $service->submit($scope, $this->questionBody('p-qa-1', 'Pending Buyer', 'Is the colour true to the photos?'), 'pending-' . bin2hex(random_bytes(6)));
        $this->createApproved($service, $scope, 'p-qa-2', 'Does the mug hold 300ml?', 'Yes, to the brim.');
        $this->createApproved($service, $otherStore, 'p-qa-1', 'Foreign store question', 'Must stay hidden.');

        $response = $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers(), ['product_id' => 'p-qa-1']);
        self::assertSame(200, $response['status']);
        self::assertIsArray($response['json']);
        self::assertCount(1, $response['json']['data']);
        self::assertSame('Does it shrink after washing?', $response['json']['data'][0]['question']);
        self::assertSame('Wash cold; it keeps its shape.', $response['json']['data'][0]['answer']);
        self::assertArrayNotHasKey('state', $response['json']['data'][0]);
        self::assertArrayNotHasKey('idempotency_key', $response['json']['data'][0]);

        $missing = $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers());
        self::assertSame(422, $missing['status']);

        $tooLong = $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers(), ['product_id' => str_repeat('p', 65)]);
        self::assertSame(422, $tooLong['status']);
    }

    public function testPublicSubmitCreatesPendingAndRejectsInvalidInput(): void
    {
        $this->enableProductQa();
        $key = 'qa-submit-' . bin2hex(random_bytes(6));

        $created = $this->call('POST', '/api/ext/siro/product-qa/public', $this->questionBody(), $this->headers(null, 'default', 'web', $key));
        self::assertSame(201, $created['status']);
        self::assertIsArray($created['json']);
        $id = $created['json']['data']['id'];
        self::assertSame('Is this machine washable?', $created['json']['data']['question']);
        self::assertNull($created['json']['data']['answer']);

        $replay = $this->call('POST', '/api/ext/siro/product-qa/public', $this->questionBody(), $this->headers(null, 'default', 'web', $key));
        self::assertSame(201, $replay['status']);
        self::assertSame($id, $replay['json']['data']['id']);

        $admin = $this->token('admin');
        $list = $this->call('GET', '/api/ext/siro/product-qa/admin', [], $this->headers($admin['access']), ['state' => 'pending']);
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['json']['data']);
        self::assertSame('pending', $list['json']['data'][0]['state']);

        $withoutKey = ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web'];
        self::assertSame(422, $this->call('POST', '/api/ext/siro/product-qa/public', $this->questionBody(), $withoutKey)['status']);

        $invalidBodies = [
            array_merge($this->questionBody(), ['customer_name' => 'A']),
            array_merge($this->questionBody(), ['question' => 'Hi']),
            array_merge($this->questionBody(), ['customer_name' => str_repeat('n', 81)]),
            array_merge($this->questionBody(), ['question' => str_repeat('q', 1001)]),
            array_merge($this->questionBody(), ['question' => '<script>alert(1)</script>']),
            array_merge($this->questionBody(), ['customer_name' => '<b>Buyer</b>']),
        ];
        foreach ($invalidBodies as $body) {
            $response = $this->call('POST', '/api/ext/siro/product-qa/public', $body, $this->headers(null, 'default', 'web', 'qa-invalid-' . bin2hex(random_bytes(6))));
            self::assertSame(422, $response['status'], 'Expected 422 for ' . json_encode($body));
        }

        $missingProduct = $this->questionBody();
        unset($missingProduct['product_id']);
        self::assertSame(422, $this->call('POST', '/api/ext/siro/product-qa/public', $missingProduct, $this->headers(null, 'default', 'web', 'qa-missing-' . bin2hex(random_bytes(6))))['status']);
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enableProductQa();

        $anonymous = $this->call('GET', '/api/ext/siro/product-qa/admin', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        self::assertSame(401, $anonymous['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/product-qa/admin', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/product-qa/admin', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
    }

    public function testAdminApproveAnswerIsIdempotentAndScopeIsolated(): void
    {
        $this->enableProductQa();
        $service = new ProductQaService();
        $scope = new CommerceScope('default', 'web', 'en', 'VND');
        $otherStore = new CommerceScope('store-b', 'web', 'en', 'VND');
        $sharedKey = 'qa-shared-' . bin2hex(random_bytes(6));

        $first = $service->submit($scope, $this->questionBody('p-qa-5', 'Buyer One', 'What material is this?'), $sharedKey);
        $second = $service->submit($otherStore, $this->questionBody('p-qa-5', 'Buyer Two', 'What material is this?'), $sharedKey);
        self::assertNotSame($this->rowId($first), $this->rowId($second));

        $admin = $this->token('admin');
        $list = $this->call('GET', '/api/ext/siro/product-qa/admin', [], $this->headers($admin['access']), ['state' => 'pending']);
        self::assertCount(1, $list['json']['data']);
        self::assertSame($this->rowId($first), $list['json']['data'][0]['id']);

        $otherList = $this->call('GET', '/api/ext/siro/product-qa/admin', [], $this->headers($admin['access'], 'store-b', 'web'), ['state' => 'pending']);
        self::assertCount(1, $otherList['json']['data']);
        self::assertSame($this->rowId($second), $otherList['json']['data'][0]['id']);

        $approved = $this->call('PUT', '/api/ext/siro/product-qa/admin/' . $this->rowId($first), ['state' => 'approved', 'answer' => 'It is 100% linen.'], $this->headers($admin['access']));
        self::assertSame(200, $approved['status']);
        self::assertSame('approved', $approved['json']['data']['state']);
        self::assertSame('It is 100% linen.', $approved['json']['data']['answer']);
        $answeredAt = $approved['json']['data']['answered_at'];
        self::assertIsString($answeredAt);

        $replay = $this->call('PUT', '/api/ext/siro/product-qa/admin/' . $this->rowId($first), ['state' => 'approved', 'answer' => 'It is 100% linen.'], $this->headers($admin['access']));
        self::assertSame(200, $replay['status']);
        self::assertSame($answeredAt, $replay['json']['data']['answered_at']);

        $publicDefault = $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers(), ['product_id' => 'p-qa-5']);
        self::assertCount(1, $publicDefault['json']['data']);
        self::assertSame('It is 100% linen.', $publicDefault['json']['data'][0]['answer']);

        $publicOther = $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers(null, 'store-b', 'web'), ['product_id' => 'p-qa-5']);
        self::assertCount(0, $publicOther['json']['data']);

        $crossStore = $this->call('PUT', '/api/ext/siro/product-qa/admin/' . $this->rowId($first), ['state' => 'rejected'], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(404, $crossStore['status']);

        $third = $service->submit($scope, $this->questionBody('p-qa-5', 'Buyer Three', 'Can I return it?'), 'qa-reject-' . bin2hex(random_bytes(6)));
        $rejected = $this->call('PUT', '/api/ext/siro/product-qa/admin/' . $this->rowId($third), ['action' => 'reject'], $this->headers($admin['access']));
        self::assertSame(200, $rejected['status']);
        self::assertSame('rejected', $rejected['json']['data']['state']);

        self::assertSame(422, $this->call('PUT', '/api/ext/siro/product-qa/admin/' . $this->rowId($third), ['state' => 'hacked'], $this->headers($admin['access']))['status']);
        self::assertSame(422, $this->call('PUT', '/api/ext/siro/product-qa/admin/' . $this->rowId($third), [], $this->headers($admin['access']))['status']);
        self::assertSame(422, $this->call('PUT', '/api/ext/siro/product-qa/admin/' . $this->rowId($third), ['answer' => '<b>no</b>'], $this->headers($admin['access']))['status']);

        $stillOne = $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers(), ['product_id' => 'p-qa-5']);
        self::assertCount(1, $stillOne['json']['data']);
    }

    public function testDisableUnplugsRoutesAndUninstallRemovesData(): void
    {
        $this->enableProductQa();
        $service = new ProductQaService();
        $scope = new CommerceScope('default', 'web', 'en', 'VND');
        $service->submit($scope, $this->questionBody(), 'qa-disable-' . bin2hex(random_bytes(6)));

        $admin = $this->token('admin');
        self::assertSame(200, $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers(), ['product_id' => 'p-qa-1'])['status']);
        self::assertSame(200, $this->call('GET', '/api/ext/siro/product-qa/admin', [], $this->headers($admin['access']))['status']);

        $lifecycle = $this->lifecycle();
        $lifecycle->disable(ProductQaExtension::id());
        $this->remountRoutes();

        self::assertSame(404, $this->call('GET', '/api/ext/siro/product-qa/public', [], $this->headers(), ['product_id' => 'p-qa-1'])['status']);
        self::assertSame(404, $this->call('GET', '/api/ext/siro/product-qa/admin', [], $this->headers($admin['access']))['status']);
        self::assertSame(200, $this->call('GET', '/health/live')['status']);

        $lifecycle->uninstall(ProductQaExtension::id(), true);
        $qaTables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_product_qa'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $qaTables);
        $products = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['products'], $products);
    }

    public function testExtensionPackagePassesQualityGate(): void
    {
        $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-product-qa-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $source = $this->basePath() . '/app/Extensions/Siro/ProductQa';
            $packed = (new ExtensionPackageInstaller($this->basePath()))->pack($source, $output);
            self::assertTrue($packed['ok']);
            self::assertFileExists($output);

            $report = (new ExtensionQualityGate($this->basePath()))->run($output);
            self::assertTrue($report['ok'], (string) json_encode($report));
            $names = array_map(static fn (array $check): string => (string) $check['name'], $report['checks']);
            foreach (['package', 'signature', 'store-scope', 'route-auth', 'secret-scan', 'php-lint'] as $name) {
                self::assertContains($name, $names);
            }
        } finally {
            @unlink($output);
        }
    }
}
