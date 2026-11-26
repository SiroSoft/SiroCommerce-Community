<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Modules\Catalog\Domain\CatalogConflict;
use App\Middleware\CorrelationIdMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\ExceptionHandlerInterface;
use Siro\Core\ValidationException;
use Siro\Core\Metrics;
use App\Support\CommerceScopeException;
use App\Support\PublicErrorMessage;
use Throwable;

final class Handler implements ExceptionHandlerInterface
{
    public static function handle(Throwable $e, Request $request): Response
    {
        $response = null;
        if ($e instanceof CommerceScopeException) {
            $response = Response::error(PublicErrorMessage::from($e->getMessage(), 'Invalid request scope'), 400, [], 'SCOPE_ERROR');
        } elseif ($e instanceof ValidationException) {
            // Keep validation errors in the same machine-readable envelope as
            // all other application errors.
            $response = Response::error('Validation failed', 422, $e->errors(), 'VALIDATION_ERROR');
        } elseif ($e instanceof CatalogConflict) {
            $response = Response::error('The resource was changed by another request', 409, ['version' => [$e->actualVersion]], 'CONFLICT');
        } elseif ($e instanceof \InvalidArgumentException) {
            $response = Response::error(PublicErrorMessage::from($e->getMessage(), 'Invalid request'), 422, [], 'INVALID_ARGUMENT');
        } elseif ($e instanceof \RuntimeException) {
            $rawMessage = $e->getMessage();
            if ($rawMessage === 'Unauthorized') {
                $response = Response::error('Unauthorized', 401, [], 'UNAUTHORIZED');
            }
            if ($response === null && preg_match('/not found|does not exist/i', $rawMessage) === 1) {
                $response = Response::error('Resource not found', 404, [], 'NOT_FOUND');
            }
        }

        $response ??= Response::error('Internal Server Error', 500, [], 'INTERNAL_ERROR');
        $errorCode = 'INTERNAL_ERROR';
        $payload = $response->payload();
        $meta = $payload['meta'] ?? null;
        if (is_array($meta) && is_string($meta['error_code'] ?? null)) {
            $errorCode = $meta['error_code'];
        }
        Metrics::counter('http_errors_total', 1, [
            'status' => (string) $response->statusCode(),
            'error_code' => $errorCode,
        ]);
        return CorrelationIdMiddleware::attach($response, CorrelationIdMiddleware::forRequest($request));
    }
}
