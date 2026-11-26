<?php

declare(strict_types=1);

namespace App\Middleware;

use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;

/** Propagates a bounded correlation ID across synchronous and async evidence. */
final class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $id = self::forRequest($request);
        $request->setAttribute('correlation_id', $id);
        $response = $next($request);
        if ($response instanceof Response) {
            self::attach($response, $id);
        }
        return $response;
    }

    public static function forRequest(Request $request): string
    {
        $existing = $request->getAttribute('correlation_id');
        if (is_string($existing) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/', $existing) === 1) {
            return $existing;
        }
        $incoming = trim((string) $request->header('x-correlation-id', ''));
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/', $incoming) === 1
            ? $incoming
            : 'cor_' . bin2hex(random_bytes(16));
    }

    public static function attach(Response $response, string $id): Response
    {
        return $response->header('X-Correlation-ID', $id);
    }
}
