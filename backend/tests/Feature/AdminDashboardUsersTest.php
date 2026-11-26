<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Merchant admin dashboard + user directory (admin UI live surfaces).
 */
final class AdminDashboardUsersTest extends TestCase
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

    /** @param array<string, mixed> $body @param array<string, string> $headers @param array<int|string, mixed> $query @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
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
    private function adminHeaders(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'admin', 'email' => 'dash-admin@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('dash-admin@shop.test', 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testDashboardStatsRequiresAdminAndReturnsLiveAggregates(): void
    {
        self::assertSame(401, $this->call('GET', '/api/dashboard/stats')['status']);
        $stats = $this->call('GET', '/api/dashboard/stats', [], $this->adminHeaders());
        self::assertSame(200, $stats['status']);
        /** @var array<string, mixed> $data */
        $data = $stats['json']['data'];
        foreach (['total_users', 'active_users', 'total_orders', 'total_products', 'total_revenue', 'recent_activity', 'api_status', 'monthly_revenue', 'orders_by_status', 'scope', 'freshness'] as $key) {
            self::assertArrayHasKey($key, $data, $key);
        }
        self::assertGreaterThanOrEqual(1, $data['total_users']);
        self::assertSame('healthy', $data['api_status']['status']);
        self::assertSame('default', $data['scope']['store_id']);
        self::assertSame('fresh', $data['freshness']['status']);
    }

    public function testUserDirectoryListsWithoutPasswordHashes(): void
    {
        $admin = $this->adminHeaders();
        $list = $this->call('GET', '/api/users', [], $admin, ['per_page' => 5]);
        self::assertSame(200, $list['status']);
        self::assertGreaterThanOrEqual(1, $list['json']['meta']['total']);
        $first = $list['json']['data'][0];
        self::assertArrayHasKey('email', $first);
        self::assertArrayNotHasKey('password', $first);

        $detail = $this->call('GET', '/api/users/1', [], $admin);
        self::assertContains($detail['status'], [200, 404]);

        $missing = $this->call('GET', '/api/users/999999', [], $admin);
        self::assertSame(404, $missing['status']);
    }
}
