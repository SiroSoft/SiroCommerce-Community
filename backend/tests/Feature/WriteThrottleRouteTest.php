<?php

declare(strict_types=1);

namespace Tests\Feature;

use Siro\Core\Router;
use Tests\TestCase;

final class WriteThrottleRouteTest extends TestCase
{
    /** @var array<string, string> */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $previousStorefront = $_ENV['THROTTLE_STOREFRONT_WRITE'] ?? null;
        $previousDefault = $_ENV['THROTTLE_WRITE_DEFAULT'] ?? null;
        $_ENV['THROTTLE_STOREFRONT_WRITE'] = '120';
        $_ENV['THROTTLE_WRITE_DEFAULT'] = '120';

        try {
            $router = new Router();
            $app = new \stdClass();
            $app->router = $router;
            require dirname(__DIR__, 2) . '/routes/api.php';
            foreach ($router->getRoutes() as $route) {
                $key = strtoupper((string) $route['method']) . ' ' . (string) $route['path'];
                $this->routes[$key] = (string) $route['middleware'];
            }
        } finally {
            if ($previousStorefront === null) {
                unset($_ENV['THROTTLE_STOREFRONT_WRITE']);
            } else {
                $_ENV['THROTTLE_STOREFRONT_WRITE'] = $previousStorefront;
            }
            if ($previousDefault === null) {
                unset($_ENV['THROTTLE_WRITE_DEFAULT']);
            } else {
                $_ENV['THROTTLE_WRITE_DEFAULT'] = $previousDefault;
            }
        }
    }

    public function testStorefrontWritesCarryTheWriteBudget(): void
    {
        $writes = [
            'POST /api/carts',
            'POST /api/carts/{id}/lines',
            'PATCH /api/carts/{id}/lines/{line}',
            'DELETE /api/carts/{id}/lines/{line}',
            'POST /api/wishlist',
            'DELETE /api/wishlist/{product}',
            'POST /api/products/{id}/reviews',
            'POST /api/products/{id}/stock-alerts',
            'POST /api/products/stock-alerts/unsubscribe',
            'POST /api/account/orders/{id}/returns',
            'PUT /api/account/notification-preferences',
            'POST /api/analytics/track',
            'POST /api/v2/payments/{id}/proof',
        ];

        foreach ($writes as $route) {
            self::assertArrayHasKey($route, $this->routes, $route . ' must stay routed');
            self::assertStringContainsString('throttle:120,1', $this->routes[$route], $route . ' needs a write budget');
        }

        self::assertStringContainsString('auth', $this->routes['POST /api/wishlist']);
        self::assertStringContainsString('auth', $this->routes['POST /api/products/{id}/reviews']);
    }

    public function testAdminWritesGetTheDefaultBudgetAndKeepTheirGuards(): void
    {
        $writes = [
            'POST /api/admin/products',
            'PUT /api/admin/products/{id}',
            'DELETE /api/admin/products/{id}',
            'POST /api/admin/orders/{id}/transition',
            'POST /api/users',
            'PUT /api/settings',
        ];

        foreach ($writes as $route) {
            self::assertArrayHasKey($route, $this->routes, $route . ' must stay routed');
            self::assertStringContainsString('throttle:120,1', $this->routes[$route], $route . ' needs the admin write budget');
        }

        self::assertStringContainsString('auth:admin', $this->routes['POST /api/admin/products']);
        self::assertStringContainsString('DemoGuardMiddleware', $this->routes['POST /api/admin/products']);
    }

    public function testWebhooksAndHealthAreNotGivenTheDefaultWriteBudget(): void
    {
        self::assertArrayHasKey('POST /api/webhooks/{provider}', $this->routes);
        self::assertStringNotContainsString('throttle:120,1', $this->routes['POST /api/webhooks/{provider}']);
        self::assertStringContainsString('throttle:10,1', $this->routes['POST /api/webhooks/{provider}']);

        foreach (['GET /health/live', 'GET /health/ready', 'GET /health/providers'] as $probe) {
            self::assertArrayHasKey($probe, $this->routes);
            self::assertStringNotContainsString('throttle', $this->routes[$probe]);
        }
    }

    public function testEveryMutatingRouteOutsideWebhooksCarriesAThrottle(): void
    {
        $missing = [];
        foreach ($this->routes as $key => $middleware) {
            [$method, $path] = explode(' ', $key, 2);
            if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                continue;
            }
            if (str_starts_with($path, '/health') || str_contains($path, '/webhooks/')) {
                continue;
            }
            if (!str_contains($middleware, 'throttle:')) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'Every write route must be rate limited');
    }
}
