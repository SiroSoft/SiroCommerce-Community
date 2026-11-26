<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Reporting\Application\ReportingService;
use App\Modules\Reporting\Contracts\ReportingContract;
use App\Resources\ReportResource;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\ApiContract;
use App\Support\CommerceScope;
use App\Support\ScopeAuthorizer;

final class ReportingController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    public function overview(Request $request): Response
    {
        $from = $request->queryString('from'); $to = $request->queryString('to');
        $storeId = $request->queryString('store_id'); $channelId = $request->queryString('channel_id');
        try { return $this->success(ReportResource::make($this->reports->overview($from === '' ? null : $from, $to === '' ? null : $to, $storeId === '' ? null : $storeId, $channelId === '' ? null : $channelId)), 'Operations report'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, [], 'INVALID_REPORTING_SCOPE'); }
    }

    public function contract(Request $request): Response
    {
        return $this->success(ReportingContract::descriptor(), 'Reporting contract');
    }

    public function failures(Request $request): Response
    {
        $result = $this->reports->failures(max(1, $request->queryInt('page', 1)), min(100, max(1, $request->queryInt('per_page', 20))));
        /** @var array<int, mixed> $data */
        $data = $result['data'];
        /** @var array{page: int, per_page: int, total: int, last_page: int} $meta */
        $meta = $result['meta'];
        return $this->paginated($data, $meta, 'Operational failures');
    }

    public function search(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); ScopeAuthorizer::assertAllowed($scope, is_array($request->user()) ? $request->user() : []); $facets = array_values(array_filter(array_map('trim', explode(',', $request->queryString('facets', ''))), static fn (string $facet): bool => $facet !== '')); return $this->success($this->reports->search($request->queryString('q', ''), $request->queryString('type', 'catalog'), max(1, $request->queryInt('page', 1)), min(100, max(1, $request->queryInt('per_page', 20))), $scope->storeId, $scope->channelId, $scope->locale, $scope->currency, $facets), 'Admin search'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\Throwable $e) { return $this->error('Search scope is not authorized', 403, [], 'FORBIDDEN'); }
    }

    public function advanced(Request $request): Response
    {
        $dimension = trim($request->queryString('dimension', 'widgets'));
        try {
            $data = $this->reports->advanced($dimension, $request->queryString('from'), $request->queryString('to'), $request->queryString('store_id'), $request->queryString('channel_id'));
            $snapshotData = is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [];
            $snapshot = is_string($snapshotData['id'] ?? null) ? $snapshotData['id'] : hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $etag = '"' . $snapshot . '"';
            if ((string) $request->header('If-None-Match', '') === $etag) return Response::json([], 304)->header('ETag', $etag)->header('Cache-Control', 'private, max-age=30');
            return $this->success($data, 'Advanced report')->header('ETag', $etag)->header('Cache-Control', 'private, max-age=30')->header('X-Report-Schema', (string) ApiContract::SCHEMA_VERSION)->header('X-Report-Snapshot', $snapshot);
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, [], 'INVALID_REPORTING_SCOPE'); }
    }
}
