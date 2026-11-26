<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\Handler;
use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\ProductionBoundaryMiddleware;
use App\Support\ProviderEvidence;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Metrics;

final class ApiObservabilityContractTest extends TestCase
{
    public function testSafeCorrelationIdIsReturnedAndInvalidInputIsReplaced(): void
    {
        $request = new Request('GET', '/health/live', [], ['X-Correlation-ID' => str_repeat('secret', 20)]);
        $response = (new CorrelationIdMiddleware())->handle($request, static fn (): Response => Response::success());

        self::assertMatchesRegularExpression('/^cor_[a-f0-9]{32}$/', (string) $response->getHeader('X-Correlation-ID'));
        self::assertSame($response->getHeader('X-Correlation-ID'), $request->getAttribute('correlation_id'));
        self::assertStringNotContainsString('secret', (string) $response->getHeader('X-Correlation-ID'));
    }

    public function testHandledErrorsRemainStructuredAndCarryCorrelation(): void
    {
        $request = new Request('GET', '/api/test', [], ['X-Correlation-ID' => 'certification-42']);
        $response = Handler::handle(new \RuntimeException('Internal dependency detail'), $request);

        self::assertSame(500, $response->statusCode());
        self::assertFalse($response->payload()['success']);
        self::assertSame('Internal Server Error', $response->payload()['message']);
        self::assertSame('certification-42', $response->getHeader('X-Correlation-ID'));
        self::assertStringNotContainsString('Internal dependency detail', json_encode($response->payload(), JSON_THROW_ON_ERROR));
    }

    public function testProviderEvidenceRedactsSecretLikeKeysRecursively(): void
    {
        $redacted = ProviderEvidence::redact(['request' => ['api_key' => 'secret', 'nested' => ['token' => 'bearer']], 'status' => 'ok']);

        self::assertSame('[redacted]', $redacted['request']['api_key']);
        self::assertSame('[redacted]', $redacted['request']['nested']['token']);
        self::assertSame('ok', $redacted['status']);
    }

    public function testProductionBoundaryAddsSecurityHeadersAndBoundsMetricRouteLabels(): void
    {
        putenv('APP_ENV=testing');
        Metrics::init('boundary-test', false);
        $response = (new ProductionBoundaryMiddleware())->handle(
            new Request('GET', '/api/products/customer@example.test/orders/secret-token'),
            static fn (): Response => Response::success()
        );

        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeader('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeader('Referrer-Policy'));
        self::assertNull($response->getHeader('Strict-Transport-Security'));
        self::assertStringNotContainsString('customer@example.test', Metrics::export());
        self::assertStringNotContainsString('secret-token', Metrics::export());
    }
}
