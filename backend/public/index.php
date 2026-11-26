<?php

declare(strict_types=1);

use Siro\Core\App;

define('BASE_PATH', dirname(__DIR__));

// Set the boundary headers before boot so configuration and unhandled errors
// cannot bypass the security contract enforced by route middleware.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

// Browser preflight lives outside route middleware: the router answers
// OPTIONS from group middleware only, so the front controller short-circuits
// preflight here with CORS headers. Actual responses get CORS from the
// route-level 'cors' group in routes/api.php.
$preflightOrigins = array_filter(array_map('trim', explode(',', (string) (getenv('CORS_ALLOWED_ORIGINS') ?: '*'))));
$preflightOrigin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    $allowOrigin = in_array('*', $preflightOrigins, true) ? '*' : (in_array($preflightOrigin, $preflightOrigins, true) ? $preflightOrigin : '');
    if ($allowOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $allowOrigin);
        // Never advertise credentials for the wildcard: browsers reject the
        // combination and it would imply ambient cookies are readable.
        header('Access-Control-Allow-Credentials: ' . ($allowOrigin === '*' ? 'false' : 'true'));
    }
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
    $requestedHeaders = isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']) && is_string($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']) ? $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] : '';
    $safeHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Request-Id', 'X-Store-Id', 'X-Channel-Id', 'X-Locale', 'X-Currency', 'X-Siro-FE', 'X-Correlation-Id', 'X-Causation-Id', 'X-Siro-Trace-Id', 'Idempotency-Key'];
    $echoHeaders = [];
    foreach (array_filter(array_map('trim', explode(',', $requestedHeaders))) as $requestedHeader) {
        foreach ($safeHeaders as $safeHeader) {
            if (strcasecmp($requestedHeader, $safeHeader) === 0) { $echoHeaders[] = $safeHeader; break; }
        }
    }
    header('Access-Control-Allow-Headers: ' . implode(',', $echoHeaders === [] ? $safeHeaders : array_values(array_unique($echoHeaders))));
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

require BASE_PATH . '/vendor/autoload.php';

$app = new App(BASE_PATH);

\Siro\Core\Router::setMiddlewareAliases([
    'auth' => \App\Middleware\AuthMiddleware::class,
    'throttle' => \App\Middleware\SecureThrottleMiddleware::class,
    'cors' => \Siro\Core\Middleware\CorsMiddleware::class,
    'json' => \Siro\Core\Middleware\JsonMiddleware::class,
    'cap' => \App\Middleware\CapabilityMiddleware::class,
    'correlation' => \App\Middleware\CorrelationIdMiddleware::class,
    'production-boundary' => \App\Middleware\ProductionBoundaryMiddleware::class,
]);

$app->boot();
(new \App\Providers\CommerceServiceProvider())->register($app);
$app->loadRoutes(BASE_PATH . '/routes/api.php');
$app->run();
