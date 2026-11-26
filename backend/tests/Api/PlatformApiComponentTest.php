<?php

declare(strict_types=1);

namespace Tests\Api;

use Siro\Core\Router;
use Siro\Core\Request;
use Siro\Core\ValidationException;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Models\Channel;
use App\Models\Store;
use App\Repositories\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PlatformApiComponentTest extends TestCase
{
    public function testPlatformRoutesHaveSemanticHttpResponsesAndCorrelation(): void
    {
        Store::create(['id' => 'default', 'code' => 'default', 'name' => 'Default Store', 'status' => 'active']);
        Channel::create(['id' => 'default', 'store_id' => 'default', 'code' => 'default', 'name' => 'Default Channel', 'status' => 'active']);
        $admin = $this->adminHeaders();
        foreach (['/health/live', '/health/ready', '/api/v2/health/queue', '/api/v2/health/payments', '/api/v2/health/inventory', '/api/v2/health/outbox', '/api/version'] as $path) {
            $response = $this->dispatch('GET', $path);
            self::assertContains($response['status'], [200, 503], $path);
            self::assertIsArray($response['json'], $path);
            self::assertNotSame('', (string) $response['correlation'], $path);
        }

        $missingScopeSearch = $this->dispatch('GET', '/api/v2/admin/search?q=missing', [], $admin);
        self::assertSame(422, $missingScopeSearch['status']);

        $search = $this->dispatch('GET', '/api/v2/admin/search?q=missing', [], $admin + ['X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        self::assertSame(200, $search['status']);
        self::assertTrue($search['json']['success']);

        $unknownPayment = $this->dispatch('POST', '/api/webhooks/unknown', ['event_id' => 'evt-platform']);
        self::assertSame(404, $unknownPayment['status']);
        $cod = $this->dispatch('POST', '/api/webhooks/cod', ['event_id' => 'evt-cod']);
        self::assertSame(404, $cod['status']);
        $shipping = $this->dispatch('POST', '/api/webhooks/shipping/dhl', ['event_id' => 'evt-shipping']);
        self::assertSame(401, $shipping['status']);
        $unknownShipping = $this->dispatch('POST', '/api/webhooks/shipping/unknown', ['event_id' => 'evt-shipping-unknown']);
        self::assertSame(401, $unknownShipping['status']);

        foreach (['/api/admin/ops/observability', '/api/admin/ops/readiness', '/api/admin/ops/metrics', '/api/admin/ops/hooks'] as $path) {
            $response = $this->dispatch('GET', $path, [], $admin);
            self::assertContains($response['status'], [200, 503], $path);
            self::assertTrue($response['json']['success'] ?? false, $path);
        }
    }

    #[DataProvider('protectedUnassignedOperations')]
    public function testUnassignedProtectedOperationsFailClosed(string $method, string $path): void
    {
        $response = $this->dispatch($method, $path);

        self::assertSame(401, $response['status'], $method . ' ' . $path);
        self::assertFalse($response['json']['success'] ?? true, $method . ' ' . $path);
        self::assertNotSame('', (string) $response['correlation'], $method . ' ' . $path);
    }

    #[DataProvider('publicInvalidOperations')]
    public function testUnassignedPublicOperationsRejectInvalidRequests(string $method, string $path): void
    {
        $response = $this->dispatch($method, $path);

        self::assertSame(422, $response['status'], $method . ' ' . $path);
        self::assertFalse($response['json']['success'] ?? true, $method . ' ' . $path);
        self::assertIsString($response['json']['message'] ?? null, $method . ' ' . $path);
        self::assertNotSame('', trim((string) $response['json']['message']), $method . ' ' . $path);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function protectedUnassignedOperations(): array
    {
        return [
            'coupons-read' => ['GET', '/api/admin/coupons'],
            'coupons-write' => ['POST', '/api/admin/coupons'],
            'coupon-disable' => ['POST', '/api/admin/coupons/coupon-1/disable'],
            'dev-doctor' => ['GET', '/api/admin/dev/doctor'],
            'dev-trace' => ['GET', '/api/admin/dev/trace/order-1'],
            'dev-recover' => ['POST', '/api/admin/dev/recover'],
            'reviews-read' => ['GET', '/api/admin/reviews'],
            'review-moderate' => ['POST', '/api/admin/reviews/review-1/moderate'],
            'smart-action-center' => ['GET', '/api/admin/smart/action-center'],
            'smart-conversion' => ['GET', '/api/admin/smart/conversion'],
            'smart-inventory' => ['GET', '/api/admin/smart/inventory'],
            'smart-search' => ['GET', '/api/admin/smart/search'],
            'smart-setup-guide' => ['GET', '/api/admin/smart/setup-guide'],
            'smart-execute' => ['POST', '/api/admin/smart/execute'],
            'smart-rescue' => ['POST', '/api/admin/smart/rescue/order-1'],
            'stores-read' => ['GET', '/api/admin/stores'],
            'store-channels-read' => ['GET', '/api/admin/stores/store-1/channels'],
            'stores-write' => ['POST', '/api/admin/stores'],
            'channels-write' => ['POST', '/api/admin/channels'],
            'auth-me' => ['GET', '/api/auth/me'],
            'logout' => ['POST', '/api/auth/logout'],
            'subscriptions-create' => ['POST', '/api/account/subscriptions'],
            'subscriptions-read' => ['GET', '/api/account/subscriptions'],
            'subscription-detail-read' => ['GET', '/api/account/subscriptions/sub-1'],
            'subscriptions-renew' => ['POST', '/api/account/subscriptions/sub-1/renew'],
            'subscriptions-transition' => ['POST', '/api/account/subscriptions/sub-1/transition'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function publicInvalidOperations(): array
    {
        return [
            'auth-login' => ['POST', '/api/auth/login'],
            'auth-refresh' => ['POST', '/api/auth/refresh'],
            'auth-register' => ['POST', '/api/auth/register'],
            'promotion-preview' => ['POST', '/api/promotions/preview'],
        ];
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: array<string, mixed>, correlation: string|null} */
    private function dispatch(string $method, string $path, array $body = [], array $headers = []): array
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        $parts = explode('?', $path, 2);
        parse_str($parts[1] ?? '', $query);
        try {
            $response = $router->dispatch(new Request($method, $parts[0], $query, $headers, $body, '127.0.0.1'));
        } catch (ValidationException $exception) {
            $response = $exception->toResponse();
        }
        ob_start();
        $response->send();
        $json = json_decode((string) ob_get_clean(), true);
        return ['status' => $response->statusCode(), 'json' => is_array($json) ? $json : [], 'correlation' => $response->getHeader('X-Correlation-ID')];
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'platform-admin', 'email' => 'platform-admin@example.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('platform-admin@example.test', 'secret123');
        return ['Authorization' => 'Bearer ' . (string) $tokens->access];
    }
}
