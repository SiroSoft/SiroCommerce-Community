<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Headless\Application\HeadlessQueryService;
use App\Modules\Headless\Domain\HeadlessException;
use App\Modules\Headless\Contracts\HeadlessSchemaReader;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Metrics;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

final class HeadlessGraphQlController extends Controller
{
    public function __construct(private readonly HeadlessQueryService $queries, private readonly HeadlessSchemaReader $schema) {}

    public function schema(Request $request): Response
    {
        return Response::json(['data' => ['schema' => $this->schema->definition(), 'limits' => $this->schema->limits()]])->header('Cache-Control', 'public, max-age=60');
    }

    public function execute(Request $request): Response
    {
        Metrics::counter('headless_graphql_requests_total', 1, ['method' => $request->method()]);
        // GraphQL documents and extensions are POST body data only. Request::input()
        // also falls back to URL parameters, which would expose sensitive queries
        // and persisted-query material in access logs and caches.
        $body = $request->body();
        $source = $body['query'] ?? null;
        if (!is_string($source)) return $this->rejected('INVALID_REQUEST', 'The query field is required.', 400);
        try {
            // Preserve the endpoint's public validation precedence: mutations and
            // persisted-query mismatches must not become scope errors.
            if (preg_match('/^\s*(?:\w+\s+)?mutation\b/i', $source) === 1) {
                return $this->rejected('READ_ONLY', 'Mutations are not supported by this endpoint.', 400);
            }
            $variables = $body['variables'] ?? [];
            if (!is_array($variables)) throw new HeadlessException('INVALID_REQUEST', 'The variables field must be an object.');
            /** @var array<string, mixed> $variables */
            $extensions = $body['extensions'] ?? [];
            if (!is_array($extensions)) throw new HeadlessException('INVALID_REQUEST', 'The extensions field must be an object.');
            if (isset($extensions['persistedQuery'])) {
                $persisted = $extensions['persistedQuery'];
                $hash = is_array($persisted) ? $persisted['sha256Hash'] ?? null : null;
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash) || !hash_equals(strtolower($hash), hash('sha256', $source))) {
                    throw new HeadlessException('VALIDATION_ERROR', 'Persisted query hash does not match the query.');
                }
            }
            $scope = CommerceScope::fromRequest($request);
            $user = $request->user();
            return Response::json(['data' => $this->queries->execute($source, is_array($user) ? $user : null, $variables, $scope)])->header('Cache-Control', 'private, no-store')->header('X-Commerce-Scope', $scope->key());
        } catch (HeadlessException $e) {
            $status = match ($e->errorCode) {
                'FORBIDDEN' => 403,
                'QUERY_TOO_LARGE' => 413,
                'REQUEST_LIMIT' => 413,
                default => 400,
            };
            return $this->rejected($e->errorCode, $e->getMessage(), $status);
        } catch (CommerceScopeException $e) {
            return $this->rejected('SCOPE_ERROR', $e->getMessage(), 400);
        }
    }

    private function rejected(string $code, string $message, int $status): Response
    {
        Metrics::counter('headless_graphql_rejections_total', 1, ['code' => $code]);
        return Response::json(['errors' => [['code' => $code, 'message' => $message]]], $status);
    }
}
