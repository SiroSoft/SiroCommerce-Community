<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\CapabilityMiddleware;
use App\Role;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;

final class ProductionBoundaryTest extends TestCase
{
    public function testRolesCannotUseCapabilitiesTheyDoNotOwn(): void
    {
        self::assertTrue(Role::can(Role::FINANCE, 'refunds'));
        self::assertFalse(Role::can(Role::SUPPORT, 'refunds'));
        self::assertFalse(Role::can(Role::VIEWER, 'orders'));
    }

    public function testCapabilityMiddlewareEnforcesTheServiceBoundary(): void
    {
        $request = new Request('POST', '/api/admin/orders/1/refund');
        $request->setUser(['id' => 7, 'role' => Role::SUPPORT]);
        $response = (new CapabilityMiddleware())->handle($request, static fn (): Response => Response::success(), 'refunds');

        self::assertSame(403, $response->statusCode());
    }

    public function testCapabilityChecksRunThroughCoreGate(): void
    {
        \Siro\Core\Gate::reset();
        $request = new Request('POST', '/api/admin/orders/1/refund');
        $request->setUser(['id' => 9, 'role' => Role::FINANCE]);
        $response = (new CapabilityMiddleware())->handle($request, static fn (): Response => Response::success(), 'refunds');

        self::assertSame(200, $response->statusCode());
        // The middleware registered a real Gate ability: controllers can now
        // check the same rule imperatively without the middleware.
        self::assertTrue(\Siro\Core\Gate::has('refunds'));
        self::assertTrue(\Siro\Core\Gate::allows('refunds', ['id' => 9, 'role' => Role::FINANCE]));
        self::assertTrue(\Siro\Core\Gate::denies('refunds', ['id' => 7, 'role' => Role::SUPPORT]));
        try {
            \Siro\Core\Gate::authorize('refunds', ['id' => 7, 'role' => Role::SUPPORT]);
            self::fail('authorize should throw for support');
        } catch (\Siro\Core\AuthorizationException) {
            self::assertTrue(true);
        }
        \Siro\Core\Gate::reset();
    }

    public function testCorrelationIdIsBoundedAndReturned(): void
    {
        $middleware = new CorrelationIdMiddleware();
        $request = new Request('GET', '/api/orders/1', [], ['X-Correlation-ID' => str_repeat('x', 200)]);
        $response = $middleware->handle($request, static fn (Request $request): Response => Response::success([
            'correlation_id' => $request->getAttribute('correlation_id'),
        ]));

        self::assertInstanceOf(Response::class, $response);
        self::assertMatchesRegularExpression('/^cor_[a-f0-9]{32}$/', (string) $response->getHeader('X-Correlation-ID'));
        self::assertSame($response->getHeader('X-Correlation-ID'), $request->getAttribute('correlation_id'));
    }

    public function testCorrelationIdPreservesSafeCallerValue(): void
    {
        $request = new Request('GET', '/health/live', [], ['X-Correlation-ID' => 'release-check:42']);
        $response = (new CorrelationIdMiddleware())->handle($request, static fn (): Response => Response::success());

        self::assertSame('release-check:42', $response->getHeader('X-Correlation-ID'));
    }
}
