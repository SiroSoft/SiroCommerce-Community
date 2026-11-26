<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\SecureThrottleMiddleware;
use App\Middleware\ProductionBoundaryMiddleware;
use App\Resources\CustomerResource;
use App\Support\PublicErrorMessage;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;

final class SecurityResidualsTest extends TestCase
{
    public function testPublicErrorMessageRemovesProviderAndInfrastructureDetails(): void
    {
        self::assertSame('Payment could not be completed', PublicErrorMessage::from('Stripe provider connection timeout: api_key invalid', 'Payment could not be completed'));
        self::assertSame('invalid coupon code', PublicErrorMessage::from('invalid coupon code', 'Invalid request'));
    }

    public function testSupportCustomerProjectionExcludesDirectPii(): void
    {
        $data = CustomerResource::make([
            'id' => 'customer-1', 'name' => 'Customer', 'email' => 'customer@example.test',
            'phone' => '+1', 'status' => 'active', 'internal_notes' => 'private',
        ], ['id', 'name', 'status']);

        self::assertSame(['id' => 'customer-1', 'name' => 'Customer', 'status' => 'active'], $data);
        self::assertArrayNotHasKey('email', $data);
        self::assertArrayNotHasKey('phone', $data);
    }

    /** @return array{real: string|false, env: mixed} */
    private static function swapEnv(string $key, string $value): array
    {
        // Core >=1.3 Env::get() prefers $_ENV over the real environment, so a
        // hermetic test must set and restore BOTH channels or siblings leak in.
        $snapshot = ['real' => getenv($key), 'env' => $_ENV[$key] ?? null];
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        return $snapshot;
    }

    /** @param array{real: string|false, env: mixed} $snapshot */
    private static function restoreEnv(string $key, array $snapshot): void
    {
        $snapshot['real'] === false ? putenv($key) : putenv($key . '=' . $snapshot['real']);
        if ($snapshot['env'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $snapshot['env'];
        }
    }

    public function testProductionThrottleDoesNotFailOpenWhenLimiterIsUnavailable(): void
    {
        $previous = self::swapEnv('APP_ENV', 'production');
        $called = false;
        $middleware = new SecureThrottleMiddleware();
        try {
            $response = $middleware->handle(new Request('GET', '/security-test', [], [], [], '127.0.0.1'), function () use (&$called): mixed {
                $called = true;
                return null;
            }, 1, 1);
        } finally {
            self::restoreEnv('APP_ENV', $previous);
        }

        self::assertFalse($called);
        // Core >=1.1 fails closed with 503 (not 429) when the limiter backend
        // is unavailable, so outages are never mistaken for limit breaches.
        self::assertSame(503, $response->statusCode());
    }

    public function testFileThrottleReturnsRetryMetadataWhenLimitIsExceeded(): void
    {
        $prevApp = self::swapEnv('APP_ENV', 'testing');
        $prevFallback = self::swapEnv('THROTTLE_FALLBACK', 'file');
        try {
            $middleware = new SecureThrottleMiddleware();
            $request = new Request('POST', '/security-test-retry', [], [], [], '127.0.0.1');
            $middleware->handle($request, static fn (): Response => Response::success(), 1, 1);
            $response = $middleware->handle($request, static fn (): Response => Response::success(), 1, 1);
        } finally {
            self::restoreEnv('APP_ENV', $prevApp);
            self::restoreEnv('THROTTLE_FALLBACK', $prevFallback);
        }

        self::assertSame(429, $response->statusCode());
        self::assertSame('1', $response->getHeader('X-RateLimit-Limit'));
        self::assertNotNull($response->getHeader('Retry-After'));
    }

    public function testProductionBoundaryDoesNotExposeTransportPolicyInTesting(): void
    {
        $previous = self::swapEnv('APP_ENV', 'testing');
        try {
            $response = (new ProductionBoundaryMiddleware())->handle(
                new Request('GET', '/health/live'),
                static fn (): Response => Response::success()
            );
        } finally {
            self::restoreEnv('APP_ENV', $previous);
        }

        self::assertSame('no-store', $response->getHeader('Cache-Control'));
        self::assertNull($response->getHeader('Strict-Transport-Security'));
    }
}
