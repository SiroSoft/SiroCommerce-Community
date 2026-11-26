<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Application\AdminWorkbenchService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class AdminConfigurationAndWorkbenchCoverageTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    public function testConfigurationReadUpdateValidationConflictAndIdempotency(): void
    {
        $headers = $this->adminHeaders();

        self::assertSame(200, $this->call('GET', '/api/admin/configuration', [], $headers)['status']);
        self::assertSame(200, $this->call('GET', '/api/admin/configuration/general', [], $headers)['status']);
        self::assertSame(404, $this->call('GET', '/api/admin/configuration/missing-group', [], $headers)['status']);

        $section = $this->call('GET', '/api/admin/configuration/general/general', [], $headers);
        self::assertSame(200, $section['status']);
        self::assertSame('SiroCommerce', $section['json']['data']['values']['site_name']);
        self::assertSame(0, $section['json']['data']['version']);
        self::assertSame(404, $this->call('GET', '/api/admin/configuration/general/missing-section', [], $headers)['status']);

        $invalidValues = [
            ['unknown' => 'value'],
            ['timezone' => 'Mars/Base'],
            ['breadcrumbs' => 'maybe'],
            ['site_name' => str_repeat('x', 121)],
            ['site_name' => '<script>alert(1)</script>'],
        ];
        foreach ($invalidValues as $values) {
            self::assertSame(422, $this->call('PUT', '/api/admin/configuration/general/general', ['values' => $values], $headers)['status']);
        }
        self::assertSame(422, $this->call('PUT', '/api/admin/configuration/catalog/inventory', ['values' => ['out_of_stock_threshold' => 'many']], $headers)['status']);
        self::assertSame(422, $this->call('PUT', '/api/admin/configuration/design/general', ['values' => ['logo' => 'ftp://bad.example/logo.png']], $headers)['status']);
        self::assertSame(422, $this->call('PUT', '/api/admin/configuration/general/general', ['values' => 'not-an-array'], $headers)['status']);

        $updated = $this->call('PUT', '/api/admin/configuration/general/general', ['values' => [
            'site_name' => 'My Shop', 'timezone' => 'UTC', 'breadcrumbs' => false,
        ]], $headers + ['Idempotency-Key' => 'config-update-1']);
        self::assertSame(200, $updated['status']);
        self::assertSame('My Shop', $updated['json']['data']['values']['general.general.site_name']);
        self::assertSame(false, $updated['json']['data']['values']['general.general.breadcrumbs']);
        self::assertSame(1, $updated['json']['data']['version']);

        $stale = $this->call('PUT', '/api/admin/configuration/general/general', [
            'values' => ['site_name' => 'Stale'], 'expected_version' => 0,
        ], $headers);
        self::assertSame(409, $stale['status']);

        $second = $this->call('PUT', '/api/admin/configuration/general/general', [
            'values' => ['site_name' => 'My Shop Two'], 'expected_version' => 1,
        ], $headers);
        self::assertSame(200, $second['status']);
        self::assertSame(2, $second['json']['data']['version']);

        Database::table('configuration_values')->insert([
            'id' => hash('sha256', 'config-replay'), 'group_key' => 'general', 'section_key' => 'general',
            'field_key' => 'replayed', 'store_id' => 'default', 'channel_id' => 'web', 'value' => '1',
            'version' => 3, 'updated_by' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $replay = $this->call('PUT', '/api/admin/configuration/general/general', [
            'values' => ['site_name' => 'Ignored'],
        ], $headers + ['Idempotency-Key' => 'config-replay']);
        self::assertSame(200, $replay['status']);
        self::assertStringContainsString('idempotent', (string) ($replay['json']['message'] ?? ''));
    }

    public function testPublicPromoBannerDefaultsUpdatesAndSanitizes(): void
    {
        $headers = $this->adminHeaders() + ['X-Channel-Id' => 'web'];
        $default = $this->call('GET', '/api/configuration/public');
        self::assertSame(200, $default['status']);
        self::assertNull($default['json']['data']);

        $enabled = $this->call('PUT', '/api/admin/configuration/custom/promo_banner', ['values' => [
            'enabled' => true, 'title' => 'Summer Sale', 'image' => '/uploads/banner.png',
        ]], $headers);
        self::assertSame(200, $enabled['status']);

        $public = $this->call('GET', '/api/configuration/public');
        self::assertSame(200, $public['status']);
        self::assertSame('Summer Sale', $public['json']['data']['promo_banner']['title']);
        self::assertSame('/uploads/banner.png', $public['json']['data']['promo_banner']['image']);

        Database::table('configuration_values')->insert([
            'id' => 'poorly-sanitized-title', 'group_key' => 'custom', 'section_key' => 'promo_banner',
            'field_key' => 'title', 'store_id' => 'pub', 'channel_id' => 'web', 'value' => 'javascript:alert(1)',
            'version' => 1, 'updated_by' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Database::table('configuration_values')->insert([
            'id' => 'poorly-sanitized-image', 'group_key' => 'custom', 'section_key' => 'promo_banner',
            'field_key' => 'image', 'store_id' => 'pub', 'channel_id' => 'web', 'value' => 'not-a-url',
            'version' => 1, 'updated_by' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Database::table('configuration_values')->insert([
            'id' => 'poorly-sanitized-enabled', 'group_key' => 'custom', 'section_key' => 'promo_banner',
            'field_key' => 'enabled', 'store_id' => 'pub', 'channel_id' => 'web', 'value' => '1',
            'version' => 1, 'updated_by' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $sanitized = $this->call('GET', '/api/configuration/public', [], ['X-Store-Id' => 'pub', 'X-Channel-Id' => 'web']);
        self::assertSame(200, $sanitized['status']);
        self::assertSame('', $sanitized['json']['data']['promo_banner']['title']);
        self::assertSame('', $sanitized['json']['data']['promo_banner']['image']);
    }

    public function testWorkbenchViewsWorkflowApprovalsAndPermissionSets(): void
    {
        $service = new AdminWorkbenchService();
        $scope = new CommerceScope('store-wb', 'channel-wb', 'en', 'USD');

        $bootstrap = $service->bootstrap($scope, 7, 'admin');
        self::assertArrayHasKey('capabilities', $bootstrap);
        self::assertArrayHasKey('permission_sets', $bootstrap);
        self::assertSame([], $bootstrap['saved_views']);

        try {
            $service->saveView($scope, 7, ['name' => '', 'resource' => 'orders', 'filters' => []]);
            self::fail('Empty view name must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Missing required string', $error->getMessage());
        }
        try {
            $service->saveView($scope, 7, ['name' => 'Mine', 'resource' => 'orders', 'filters' => 'bad']);
            self::fail('Non-array filters must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('filters', $error->getMessage());
        }

        $view = $service->saveView($scope, 7, ['name' => 'Mine', 'resource' => 'orders', 'filters' => ['state' => 'paid'], 'columns' => ['id']]);
        self::assertSame('Mine', $view['name']);
        self::assertSame(['state' => 'paid'], $view['filters']);
        self::assertCount(1, $service->bootstrap($scope, 7, 'admin')['saved_views']);

        $service->deleteView((string) $view['id'], $scope, 7);
        self::assertSame([], $service->bootstrap($scope, 7, 'admin')['saved_views']);
        try {
            $service->deleteView('missing-view', $scope, 7);
            self::fail('Missing saved view must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }

        foreach ([
            ['name' => 'W', 'resource' => 'orders', 'action' => 'approve', 'steps' => []],
            ['name' => 'W', 'resource' => 'orders', 'action' => 'approve', 'steps' => ['bogus']],
            ['name' => 'W', 'resource' => 'orders', 'action' => 'approve', 'steps' => ['admin', 'owner', 'finance', 'support', 'marketer', 'warehouse']],
        ] as $invalidWorkflow) {
            try {
                $service->createWorkflow($scope, 7, $invalidWorkflow);
                self::fail('Invalid workflow steps must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $workflow = $service->createWorkflow($scope, 7, ['name' => 'Order approvals', 'resource' => 'orders', 'action' => 'approve', 'steps' => ['admin', 'owner']]);
        self::assertSame(['admin', 'owner'], $workflow['steps']);

        try {
            $service->requestApproval($scope, 7, ['workflow_id' => 'missing', 'resource_id' => 'order-1']);
            self::fail('Missing workflow must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }
        $approval = $service->requestApproval($scope, 7, ['workflow_id' => $workflow['id'], 'resource_id' => 'order-1', 'payload' => ['reason' => 'ok']]);
        self::assertSame('pending', $approval['state']);
        self::assertSame(['reason' => 'ok'], $approval['payload']);

        try {
            $service->decideApproval((string) $approval['id'], $scope, 8, 'admin', 'maybe');
            self::fail('Unsupported decisions must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('decision', $error->getMessage());
        }
        try {
            $service->decideApproval('missing-approval', $scope, 8, 'admin', 'approved');
            self::fail('Missing approvals must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }
        try {
            $service->decideApproval((string) $approval['id'], $scope, 8, 'support', 'approved');
            self::fail('Non-approver roles must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not an approver', $error->getMessage());
        }
        $decided = $service->decideApproval((string) $approval['id'], $scope, 8, 'admin', 'approved');
        self::assertSame('approved', $decided['state']);
        try {
            $service->decideApproval((string) $approval['id'], $scope, 8, 'admin', 'approved');
            self::fail('Already-decided approvals must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('already decided', $error->getMessage());
        }

        self::assertTrue(AdminWorkbenchService::allowsConfigured('owner', 'reports'));
        self::assertNull(AdminWorkbenchService::allowsConfigured('support', 'reports'));

        $saved = $service->savePermissionSet(1, 'support', ['orders', 'not-a-capability']);
        self::assertSame(['support' => ['orders']], $saved);
        self::assertTrue(AdminWorkbenchService::allowsConfigured('support', 'orders'));
        self::assertFalse(AdminWorkbenchService::allowsConfigured('support', 'reports'));
        $service->savePermissionSet(1, 'support', ['customers']);
        self::assertSame(['support' => ['customers']], $service->savePermissionSet(1, 'support', ['customers']));
        try {
            $service->savePermissionSet(1, 'owner', ['orders']);
            self::fail('Owner permissions must not be configurable.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('non-owner', $error->getMessage());
        }
        try {
            $service->savePermissionSet(1, 'bogus', ['orders']);
            self::fail('Unknown roles must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('non-owner', $error->getMessage());
        }
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $email = 'config-admin-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'Config Admin', 'email' => $email, 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: array<string, mixed>} */
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
        $decoded = json_decode($output, true);
        /** @var array<string, mixed> $decoded */
        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }
}
