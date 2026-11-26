<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MetricsController;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Tests\TestCase;

/**
 * GET /metrics contract: snapshot-backed Prometheus text, static-token gated,
 * and fail-closed in production when no token is configured.
 */
final class MetricsEndpointTest extends TestCase
{
    private string $snapshotPath;
    private bool $hadSnapshot = false;
    private ?string $originalSnapshot = null;
    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotPath = dirname(__DIR__, 2) . '/storage/framework/metrics.json';
        $this->hadSnapshot = is_file($this->snapshotPath);
        $this->originalSnapshot = $this->hadSnapshot ? (string) file_get_contents($this->snapshotPath) : null;

        foreach (['METRICS_TOKEN', 'THROTTLE_FALLBACK'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            $this->setEnv($key, null);
        }
    }

    protected function tearDown(): void
    {
        $this->setEnv('APP_ENV', 'testing');
        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value);
        }

        if ($this->hadSnapshot && $this->originalSnapshot !== null) {
            file_put_contents($this->snapshotPath, $this->originalSnapshot);
        } elseif (is_file($this->snapshotPath)) {
            @unlink($this->snapshotPath);
        }

        parent::tearDown();
    }

    public function testTokenIsRequiredWhenMetricsTokenIsConfigured(): void
    {
        $this->setEnv('METRICS_TOKEN', 'scrape-secret');
        $this->writeSnapshot($this->fixture());

        $missing = $this->call('/metrics');
        self::assertSame(401, $missing['status']);
        self::assertStringContainsString('text/plain', strtolower($missing['contentType']));

        $wrong = $this->call('/metrics', ['Authorization' => 'Bearer nope']);
        self::assertSame(403, $wrong['status']);

        $bearer = $this->call('/metrics', ['Authorization' => 'Bearer scrape-secret']);
        self::assertSame(200, $bearer['status']);
        self::assertStringContainsString('# TYPE siro_http_requests_total counter', $bearer['body']);

        $headerToken = $this->call('/metrics', ['X-Metrics-Token' => 'scrape-secret']);
        self::assertSame(200, $headerToken['status']);
    }

    public function testProductionWithoutTokenReturns404(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('METRICS_TOKEN', null);
        $this->writeSnapshot($this->fixture());

        $response = (new MetricsController())->index($this->request('/metrics'));

        self::assertSame(404, $response->statusCode());
        self::assertSame('', $this->body($response));
    }

    public function testDevSnapshotRendersPrometheusTextWithSafeNames(): void
    {
        $this->writeSnapshot($this->fixture());

        $result = $this->call('/metrics');

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('text/plain', strtolower($result['contentType']));
        self::assertStringContainsString('version=0.0.4', $result['contentType']);

        $body = $result['body'];
        self::assertStringContainsString('# HELP siro_http_requests_total ', $body);
        self::assertStringContainsString('# TYPE siro_http_requests_total counter', $body);
        self::assertStringContainsString('siro_http_requests_total{method="GET",route="/health/live",status="200"} 42', $body);

        self::assertStringContainsString('# TYPE siro_http_response_duration_ms histogram', $body);
        self::assertStringContainsString('siro_http_response_duration_ms_bucket{method="GET",route="/health/live",status="200",le="50"} 3', $body);
        self::assertStringContainsString('siro_http_response_duration_ms_bucket{method="GET",route="/health/live",status="200",le="+Inf"} 6', $body);
        self::assertStringContainsString('siro_http_response_duration_ms_sum{method="GET",route="/health/live",status="200"} 530', $body);
        self::assertStringContainsString('siro_http_response_duration_ms_count{method="GET",route="/health/live",status="200"} 6', $body);

        self::assertStringContainsString('# TYPE siro_queue_backlog gauge', $body);
        self::assertStringContainsString('siro_queue_backlog{queue="default"} 3', $body);

        // Unsafe metric/label names and values are sanitized, never emitted raw.
        self::assertStringContainsString('siro_bad_metric{bad_label="va\\"l\\\\ue"} 1', $body);
        self::assertStringNotContainsString('bad label=', $body);

        // HELP text must never smuggle a raw newline into the exposition.
        self::assertStringContainsString('# HELP siro_http_requests_total Counter http_requests_total\\nwith newline', $body);
    }

    public function testMissingSnapshotReturnsEmpty200(): void
    {
        if (is_file($this->snapshotPath)) {
            @unlink($this->snapshotPath);
        }

        $result = $this->call('/metrics');

        self::assertSame(200, $result['status']);
        self::assertSame('', $result['body']);
        self::assertStringContainsString('text/plain', strtolower($result['contentType']));
    }

    public function testRouteIsRegisteredWithScrapeProtection(): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $middleware = null;
        foreach ($router->getRoutes() as $route) {
            if (strtoupper((string) $route['method']) === 'GET' && (string) $route['path'] === '/metrics') {
                $middleware = (string) $route['middleware'];
                break;
            }
        }

        self::assertNotNull($middleware, 'GET /metrics must stay routed');
        self::assertStringContainsString('CorrelationIdMiddleware', $middleware);
        self::assertStringContainsString('throttle:60,1', $middleware);
        self::assertStringNotContainsString('auth:', $middleware);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return [
            'counters' => [
                'http_requests_total::method=GET,route=/health/live,status=200' => [
                    'type' => 'counter',
                    'help' => "Counter http_requests_total\nwith newline",
                    'labels' => ['method' => 'GET', 'route' => '/health/live', 'status' => '200'],
                    'value' => 42,
                ],
                'bad metric::bad label=va"l\\ue' => [
                    'type' => 'counter',
                    'help' => 'Counter bad metric',
                    'labels' => ['bad label' => 'va"l\\ue'],
                    'value' => 1,
                ],
            ],
            'histograms' => [
                'http_response_duration_ms' => [
                    'buckets' => [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000],
                    'values' => [
                        'method=GET,route=/health/live,status=200' => [0, 0, 0, 1, 2, 3, 0, 0, 0, 0, 0],
                    ],
                    'sum' => ['method=GET,route=/health/live,status=200' => 530.0],
                    'count' => ['method=GET,route=/health/live,status=200' => 6],
                ],
            ],
            'gauges' => [
                'queue_backlog::queue=default' => [
                    'help' => 'Gauge queue_backlog',
                    'value' => 3,
                    'labels' => ['queue' => 'default'],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function writeSnapshot(array $snapshot): void
    {
        $directory = dirname($this->snapshotPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($this->snapshotPath, json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, contentType: string}
     */
    private function call(string $path, array $headers = []): array
    {
        $response = (new MetricsController())->index($this->request($path, $headers));

        return [
            'status' => $response->statusCode(),
            'body' => $this->body($response),
            'contentType' => (string) $response->getHeader('Content-Type'),
        ];
    }

    /** @param array<string, string> $headers */
    private function request(string $path, array $headers = []): Request
    {
        return new Request('GET', $path, [], $headers, [], '127.0.0.1');
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->send();
        return (string) ob_get_clean();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
