<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiContract;
use App\Exceptions\Handler;
use App\Middleware\AuthMiddleware;
use App\Middleware\CapabilityMiddleware;
use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\DemoGuardMiddleware;
use App\Middleware\FeGuardMiddleware;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\ValidationException;

final class PhaseV6HardeningTest extends TestCase
{
    public function testVersionMetadataDescribesTheGeneratedClientAndStableBounds(): void
    {
        $metadata = ApiContract::metadata();

        self::assertSame('typescript', $metadata['client']['sdk']);
        self::assertSame(ApiContract::SCHEMA_VERSION, $metadata['client']['contract']);
        self::assertSame(100, $metadata['pagination']['max_per_page']);
        self::assertSame('envelope', $metadata['error_format']);
    }

    public function testErrorAndPaginationEnvelopesRemainMachineReadable(): void
    {
        $error = Response::error('Invalid input', 422, ['name' => ['Required']], 'VALIDATION_ERROR')->payload();
        self::assertFalse($error['success']);
        self::assertSame('VALIDATION_ERROR', $error['meta']['error_code']);
        self::assertSame(['name' => ['Required']], $error['meta']['errors']);
        self::assertArrayHasKey('timestamp', $error['meta']);

        $page = Response::paginated(['item'], ['page' => 2, 'per_page' => 20, 'total' => 21, 'last_page' => 2])->payload();
        self::assertSame(['page' => 2, 'per_page' => 20, 'total' => 21, 'last_page' => 2], array_intersect_key($page['meta'], array_flip(['page', 'per_page', 'total', 'last_page'])));
    }

    public function testValidationHandlerAddsStableCodeAndCorrelationId(): void
    {
        $request = new Request('POST', '/api/test', [], ['X-Correlation-ID' => 'v6-test'], [], '127.0.0.1');
        $response = Handler::handle(new ValidationException(['email' => ['Invalid']]), $request);

        self::assertSame(422, $response->statusCode());
        self::assertSame('v6-test', $response->getHeader('X-Correlation-ID'));
        self::assertSame('VALIDATION_ERROR', $response->payload()['meta']['error_code']);
    }

    public function testAuthAndCapabilityMiddlewareFailClosedWithoutCredentials(): void
    {
        $request = new Request('GET', '/api/admin/test', [], [], [], '127.0.0.1');
        $auth = (new AuthMiddleware())->handle($request, static fn (): Response => Response::success(), 'admin');
        self::assertSame(401, $auth->statusCode());
        self::assertSame('UNAUTHORIZED', $auth->payload()['meta']['error_code']);

        $capability = (new CapabilityMiddleware())->handle($request, static fn (): Response => Response::success(), 'reports');
        self::assertSame(403, $capability->statusCode());
    }

    public function testFrontendAndDemoGuardsUseStableForbiddenCode(): void
    {
        $request = new Request('POST', '/api/admin/test', [], ['X-Siro-FE' => 'wrong'], [], '127.0.0.1');
        $_ENV['FE_SHARED_SECRET'] = 'expected';
        $frontend = (new FeGuardMiddleware())->handle($request, static fn (): Response => Response::success());
        self::assertSame('FORBIDDEN', $frontend->payload()['meta']['error_code']);

        $request->setUser(['role' => 'viewer', 'email' => 'demo@example.test']);
        $demo = (new DemoGuardMiddleware())->handle($request, static fn (): Response => Response::success());
        self::assertSame('FORBIDDEN', $demo->payload()['meta']['error_code']);
        unset($_ENV['FE_SHARED_SECRET']);
    }

    public function testCorrelationIdsAcceptOnlyBoundedSafeValues(): void
    {
        $request = new Request('GET', '/api/test', [], ['X-Correlation-ID' => str_repeat('x', 97)], [], '127.0.0.1');
        $id = CorrelationIdMiddleware::forRequest($request);

        self::assertMatchesRegularExpression('/^cor_[a-f0-9]{32}$/', $id);
    }
}
