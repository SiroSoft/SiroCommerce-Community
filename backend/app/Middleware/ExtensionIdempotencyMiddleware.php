<?php

declare(strict_types=1);

namespace App\Middleware;

use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Requires a valid Idempotency-Key on extension mutations (spec RULE-E4).
 * Safe methods pass through; replay safety is the extension's own job.
 */
final class ExtensionIdempotencyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $method = strtoupper($request->method());
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return $next($request);
        }
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            return Response::error('A valid Idempotency-Key is required', 422);
        }
        return $next($request);
    }
}
