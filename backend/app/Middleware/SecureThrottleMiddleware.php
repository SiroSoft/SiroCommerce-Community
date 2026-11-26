<?php

declare(strict_types=1);

namespace App\Middleware;

use Siro\Core\Env;
use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;

/** Keeps production throttles fail-closed when the shared limiter is unavailable. */
final class SecureThrottleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, int $maxRequests = 60, int $minutes = 1): mixed
    {
        $production = strtolower(trim((string) Env::get('APP_ENV', 'production'))) === 'production';
        $previous = getenv('THROTTLE_FALLBACK');
        $fallback = $production ? 'fail_closed' : ((is_string($previous) && $previous !== '') ? $previous : 'file');
        putenv('THROTTLE_FALLBACK=' . $fallback);
        $_ENV['THROTTLE_FALLBACK'] = $fallback;
        // Routes can stack more than one throttle (generous read budget plus a
        // tight write budget). The core limiter keys on method+path only, so
        // stacked limits would share one counter and halve the effective
        // budget. Namespace the limiter key by budget and hand the original
        // request to the rest of the pipeline untouched.
        $keyedRequest = new Request(
            $request->method(),
            $request->path() . '/@throttle-' . max(1, $maxRequests) . '-' . max(1, $minutes),
            $request->queryAll(),
            $request->headers(),
            $request->body(),
            $request->ip()
        );
        try {
            return (new ThrottleMiddleware())->handle($keyedRequest, static fn ($ignored = null) => $next($request), $maxRequests, $minutes);
        } finally {
            if ($previous === false) {
                putenv('THROTTLE_FALLBACK');
                unset($_ENV['THROTTLE_FALLBACK']);
            } else {
                putenv('THROTTLE_FALLBACK=' . $previous);
                $_ENV['THROTTLE_FALLBACK'] = $previous;
            }
        }
    }
}
