<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class DashboardBiRouteTest extends TestCase
{
    public function testAnonymousAccessIsRejected(): void
    {
        $response = $this->dispatch('GET', '/api/v2/admin/dashboard/bi?range=7d');
        self::assertSame(401, $response['status']);
    }

    public function testAdminAndFinanceReceiveScopedBusinessPulse(): void
    {
        $admin = $this->headers('dashboard-admin@example.test', 'admin');
        $finance = $this->headers('dashboard-finance@example.test', 'finance');

        foreach ([$admin, $finance] as $headers) {
            $response = $this->dispatch('GET', '/api/v2/admin/dashboard/bi?range=7d', $headers);
            self::assertSame(200, $response['status']);
            self::assertTrue($response['json']['success'] ?? false);
            /** @var array<string, mixed> $data */
            $data = $response['json']['data'] ?? [];
            foreach (['range', 'sales', 'funnel', 'top_products', 'low_stock', 'ops'] as $key) {
                self::assertArrayHasKey($key, $data);
            }
            self::assertSame('7d', $data['range']);
            self::assertSame(['sessions', 'product_views', 'add_to_cart', 'checkout_started', 'orders'], array_keys($data['funnel']));
        }
    }

    public function testInvalidRangeIsAValidationError(): void
    {
        $response = $this->dispatch('GET', '/api/v2/admin/dashboard/bi?range=14d', $this->headers('dashboard-range@example.test', 'admin'));
        self::assertSame(422, $response['status']);
        self::assertSame('INVALID_RANGE', $response['json']['meta']['error_code'] ?? null);
    }

    public function testRolesOutsideAdminOwnerFinanceAreForbidden(): void
    {
        $response = $this->dispatch('GET', '/api/v2/admin/dashboard/bi?range=7d', $this->headers('dashboard-marketer@example.test', 'marketer'));
        self::assertSame(403, $response['status']);
    }

    /** @return array<string, string> */
    private function headers(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $token = $auth->login($email, 'secret123')->access;
        return [
            'Authorization' => 'Bearer ' . (string) $token,
            'X-Store-Id' => 'store-1',
            'X-Channel-Id' => 'channel-1',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, json: array<string, mixed>}
     */
    private function dispatch(string $method, string $path, array $headers = []): array
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        $parts = explode('?', $path, 2);
        parse_str($parts[1] ?? '', $query);
        try {
            $response = $router->dispatch(new Request($method, $parts[0], $query, $headers, [], '127.0.0.1'));
        } catch (ValidationException $exception) {
            $response = $exception->toResponse();
        }
        ob_start();
        $response->send();
        $json = json_decode((string) ob_get_clean(), true);
        return ['status' => $response->statusCode(), 'json' => is_array($json) ? $json : []];
    }
}
