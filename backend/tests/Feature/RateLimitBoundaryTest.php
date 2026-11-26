<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\SecureThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Rate-limit boundary: the configured write budget returns 429, and a missing
 * limiter backend in production fails closed with 503 (never a silent allow).
 */
final class RateLimitBoundaryTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $previous = $_ENV['THROTTLE_STOREFRONT_WRITE'] ?? null;
        $_ENV['THROTTLE_STOREFRONT_WRITE'] = '2';
        try {
            $this->router = new Router();
            $stub = new \stdClass();
            $stub->router = $this->router;
            $app = $stub;
            require dirname(__DIR__, 2) . '/routes/api.php';
        } finally {
            if ($previous === null) {
                unset($_ENV['THROTTLE_STOREFRONT_WRITE']);
            } else {
                $_ENV['THROTTLE_STOREFRONT_WRITE'] = $previous;
            }
        }
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, headers: array<string, string>, output: string} */
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
        return ['status' => $response->statusCode(), 'headers' => $response->headers(), 'output' => $output];
    }

    public function testWriteRouteReturns429AtTheConfiguredBudget(): void
    {
        $first = $this->call('POST', '/api/carts');
        $second = $this->call('POST', '/api/carts');
        $third = $this->call('POST', '/api/carts');

        self::assertSame(201, $first['status']);
        self::assertSame(201, $second['status']);
        self::assertSame(429, $third['status']);
        self::assertStringContainsString('Max 2 requests per 1 minute', $third['output']);
        self::assertArrayHasKey('Retry-After', $third['headers']);
    }

    public function testProductionWithoutLimiterBackendFailsClosedWith503(): void
    {
        $previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $previousFallback = getenv('THROTTLE_FALLBACK');
        $_ENV['APP_ENV'] = 'production';
        putenv('THROTTLE_FALLBACK');
        unset($_ENV['THROTTLE_FALLBACK']);
        try {
            $response = (new SecureThrottleMiddleware())->handle(
                new Request('POST', '/api/carts', [], [], [], '127.0.0.1'),
                static fn (): Response => Response::json(['success' => true], 201),
                5,
                1
            );
        } finally {
            if ($previousAppEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousAppEnv;
            }
            if ($previousFallback === false) {
                putenv('THROTTLE_FALLBACK');
                unset($_ENV['THROTTLE_FALLBACK']);
            } else {
                putenv('THROTTLE_FALLBACK=' . $previousFallback);
                $_ENV['THROTTLE_FALLBACK'] = $previousFallback;
            }
        }

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(503, $response->statusCode());
        self::assertSame('unavailable', $response->headers()['X-RateLimit-Backend'] ?? null);
    }
}
