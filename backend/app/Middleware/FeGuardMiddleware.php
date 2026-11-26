<?php

declare(strict_types=1);

namespace App\Middleware;

use Siro\Core\Env;
use Siro\Core\Logger;
use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * FeGuardMiddleware - restricts /api access to known frontends.
 *
 * Two trust paths are accepted:
 *  (a) X-Siro-FE matches FE_SHARED_SECRET (hash_equals), or
 *  (b) no token is supplied and the request Origin/Referer matches an entry
 *      in FE_ALLOWED_ORIGINS (exact origin match, no wildcards).
 *
 * The origin fallback is storefront-only: admin paths still require the shared
 * token, because a browser on an allowed origin must never gain admin API
 * access without it. Throttling remains a separate route middleware, so the
 * fallback never bypasses request budgets. Each fallback is recorded in the
 * security log for metric collection.
 *
 * Fail-open only for local development when FE_SHARED_SECRET is unset and no
 * origin proof is available. Production stays closed. OPTIONS preflight
 * requests always pass (CORS is handled separately).
 */
final class FeGuardMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Siro-FE';

    /** Production origin plus local development ports. */
    public const DEFAULT_ALLOWED_ORIGINS = 'https://ecommerce.sirophp.com,https://admin.sirophp.com,http://localhost:3000,http://localhost:3005,http://127.0.0.1:3000,http://127.0.0.1:3005';

    /**
     * @param Request $request The incoming request
     * @param callable $next The next middleware/handler
     * @return mixed Next response or 403 when the request is untrusted
     */
    public function handle(Request $request, callable $next): mixed
    {
        if (strtoupper($request->method()) === 'OPTIONS') {
            return $next($request);
        }

        $secret = (string) Env::get('FE_SHARED_SECRET', '');
        $given = (string) $request->header('x-siro-fe', '');
        $production = strtolower(trim((string) Env::get('APP_ENV', 'production'))) === 'production';
        $isAdmin = self::isAdminPath($request->path());

        if ($secret !== '' && $given !== '' && hash_equals($secret, $given)) {
            return $next($request);
        }

        if ($given !== '' && $secret !== '') {
            return self::forbidden('Unknown frontend. Missing or invalid client token.');
        }

        // Origin fallback is never extended to admin routes.
        if (!$isAdmin) {
            $origin = self::requestOrigin($request);
            if ($origin !== '' && in_array($origin, self::allowedOrigins(), true)) {
                Logger::security('fe_guard.origin_fallback', [
                    'origin' => $origin,
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);
                return $next($request);
            }
        }

        if ($secret === '') {
            if ($production && $isAdmin) {
                return Response::error('Service unavailable', 503, [
                    'fe' => ['Frontend trust configuration is missing.'],
                ], 'TRUST_CONFIG_MISSING');
            }
            if (!$production) {
                return $next($request);
            }
        }

        return self::forbidden('Unknown frontend. Missing or invalid client token.');
    }

    private static function forbidden(string $message): Response
    {
        return Response::error('Forbidden', 403, [
            'fe' => [$message],
        ], 'FORBIDDEN');
    }

    private static function isAdminPath(string $path): bool
    {
        $path = strtolower($path);

        return str_contains($path, '/admin/')
            || str_ends_with($path, '/admin')
            || str_starts_with($path, '/api/admin')
            || str_starts_with($path, '/api/v2/admin');
    }

    private static function requestOrigin(Request $request): string
    {
        $origin = self::normalizeOrigin((string) $request->header('origin', ''));
        if ($origin !== '') {
            return $origin;
        }

        return self::normalizeOrigin((string) $request->header('referer', ''));
    }

    /** @return array<int, string> */
    private static function allowedOrigins(): array
    {
        $raw = (string) Env::get('FE_ALLOWED_ORIGINS', self::DEFAULT_ALLOWED_ORIGINS);
        $allowed = [];
        foreach (explode(',', $raw) as $candidate) {
            $origin = self::normalizeOrigin($candidate);
            if ($origin !== '') {
                $allowed[] = $origin;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Normalizes an Origin/Referer value to scheme://host[:port].
     * Wildcards, "null", malformed values and non-HTTP schemes are rejected.
     */
    private static function normalizeOrigin(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') {
            return '';
        }

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            return '';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }
}
