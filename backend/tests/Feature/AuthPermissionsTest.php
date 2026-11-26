<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * /api/auth/me exposes the effective admin capability list so the console can
 * gate navigation without guessing from URLs or labels.
 */
final class AuthPermissionsTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $stub = new \stdClass();
        $stub->router = $this->router;
        $app = $stub;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
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
        /** @var array{status: int, json: mixed} $out */
        $out = ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
        return $out;
    }

    /** @return array<string, string> */
    private function headersFor(string $role, string $email): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $created = $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        if ($role === 'customer') {
            (new UserRepository())->update((int) $created['id'], ['role' => 'customer']);
        }
        $tokens = $auth->login($email, 'secret123');

        return ['Authorization' => 'Bearer ' . $tokens->access];
    }

    /** @return list<string> */
    private function permissions(array $headers): array
    {
        $me = $this->call('GET', '/api/auth/me', [], $headers);
        self::assertSame(200, $me['status']);
        /** @var array<string, mixed> $data */
        $data = $me['json']['data'];
        self::assertArrayHasKey('permissions', $data);
        self::assertIsArray($data['permissions']);
        /** @var list<string> $permissions */
        $permissions = $data['permissions'];

        return $permissions;
    }

    public function testOwnerHasWildcard(): void
    {
        self::assertSame(['*'], $this->permissions($this->headersFor('owner', 'perm-owner@shop.test')));
    }

    public function testAdminGetsEveryAdminCapability(): void
    {
        self::assertSame([
            'admin_workbench', 'ai_suggestions', 'analytics', 'campaigns', 'cms', 'coupons',
            'customers', 'exports', 'inventory', 'operations', 'orders', 'products',
            'reports', 'search', 'seo', 'subscriptions', 'users',
        ], $this->permissions($this->headersFor('admin', 'perm-admin@shop.test')));
    }

    public function testMarketerSet(): void
    {
        self::assertSame([
            'ai_suggestions', 'analytics', 'campaigns', 'cms', 'coupons', 'products', 'seo',
        ], $this->permissions($this->headersFor('marketer', 'perm-marketer@shop.test')));
    }

    public function testSupportSet(): void
    {
        self::assertSame([
            'customers', 'operations', 'orders', 'subscriptions',
        ], $this->permissions($this->headersFor('support', 'perm-support@shop.test')));
    }

    public function testFinanceSetIncludesReadOnlyReportingAndSearch(): void
    {
        self::assertSame([
            'ai_suggestions', 'credit_memos', 'exports', 'finance', 'invoices', 'operations',
            'payments', 'refunds', 'reports', 'search', 'subscriptions',
        ], $this->permissions($this->headersFor('finance', 'perm-finance@shop.test')));
    }

    public function testCustomerHasNoAdminCapabilities(): void
    {
        $permissions = $this->permissions($this->headersFor('customer', 'perm-customer@shop.test'));
        self::assertSame([], $permissions);
        foreach (['users', 'orders', 'products', 'finance', 'admin_workbench', '*'] as $capability) {
            self::assertNotContains($capability, $permissions);
        }
    }

    public function testViewerPermissionsAreAlwaysAnArray(): void
    {
        self::assertSame([], $this->permissions($this->headersFor('viewer', 'perm-viewer@shop.test')));
    }

    public function testConfiguredPermissionSetOverridesRoleDefaults(): void
    {
        $headers = $this->headersFor('support', 'perm-support-override@shop.test');
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO admin_permission_sets (role,permissions_json,updated_at) VALUES (?,?,?)')
            ->execute(['support', json_encode(['orders'], JSON_THROW_ON_ERROR), date('Y-m-d H:i:s')]);

        self::assertSame(['orders'], $this->permissions($headers));

        $pdo->prepare('UPDATE admin_permission_sets SET permissions_json=? WHERE role=?')
            ->execute([json_encode([], JSON_THROW_ON_ERROR), 'support']);

        self::assertSame([], $this->permissions($headers), 'An explicit empty set must not fall back to role defaults.');
    }
}
