<?php

declare(strict_types=1);

namespace App\Middleware;

use Siro\Core\Env;
use Siro\Core\Metrics;
use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;

/** Adds safe response headers and bounded request metrics at the HTTP boundary. */
final class ProductionBoundaryMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $startedAt = microtime(true);
        $response = $next($request);
        if (!$response instanceof Response) {
            return $response;
        }

        $status = $response->statusCode();
        $labels = ['method' => strtoupper($request->method()), 'route' => $this->routeBucket($request->path()), 'status' => (string) $status];
        Metrics::counter('http_requests_total', 1, $labels);
        Metrics::histogram('http_response_duration_ms', (microtime(true) - $startedAt) * 1000, $labels);

        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Referrer-Policy', 'no-referrer');
        $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        if (strtolower((string) Env::get('APP_ENV', 'production')) === 'production') {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (str_starts_with($request->path(), '/health/') || str_starts_with($request->path(), '/api/admin/ops/')) {
            $response->header('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function routeBucket(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) <= 2) {
            return '/' . implode('/', $segments);
        }

        return '/' . implode('/', array_slice($segments, 0, 2)) . '/{route}';
    }
}
