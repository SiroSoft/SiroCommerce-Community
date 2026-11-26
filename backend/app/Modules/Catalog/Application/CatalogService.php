<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogReader;
use Siro\Core\Cache;
use Siro\Core\Metrics;
use App\Support\CommerceScope;

final class CatalogService
{
    public function __construct(private readonly CatalogReader $catalog)
    {
    }

    /** @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int, facets?: array<string, mixed>}}
     */
    public function listActive(int $page = 1, int $perPage = 20, ?CommerceScope $scope = null, array $filters = []): array
    {
        $scopeKey = $scope === null ? 'global' : $scope->key();
        $key = "catalog:active:{$scopeKey}:{$page}:{$perPage}:" . md5((string) json_encode($this->canonicalFilters($filters)));
        $result = Cache::remember($key, 30, function () use ($page, $perPage, $scope, $filters): array {
            Metrics::counter('commerce_catalog_reads_total', 1, ['operation' => 'list']);
            return $this->catalog->activePage($page, $perPage, $scope, $filters);
        });
        return is_array($result) && isset($result['data'], $result['meta']) ? $this->normalizePage($result) : ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
    }

    /** @param array<mixed, mixed> $filters
     * @return array<mixed, mixed> */
    private function canonicalFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $key => $value) $out[$key] = is_array($value) ? $this->canonicalFilters($value) : $value;
        if (!array_is_list($out)) ksort($out);
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function show(string $slug, ?CommerceScope $scope = null): ?array
    {
        $scopeKey = $scope === null ? 'global' : $scope->key();
        $key = 'catalog:slug:' . $scopeKey . ':' . $slug;
        $cached = Cache::remember($key, 60, function () use ($slug, $scope): ?array {
            Metrics::counter('commerce_catalog_reads_total', 1, ['operation' => 'show']);
            return $scope === null ? $this->catalog->bySlug($slug) : $this->catalog->bySlug($slug, $scope);
        });
        return is_array($cached) ? $this->normalizeRow($cached) : null;
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int, facets?: array<string, mixed>}}
     */
    private function normalizePage(array $value): array
    {
        $data = [];
        if (is_array($value['data'] ?? null)) foreach ($value['data'] as $row) if (is_array($row)) $data[] = $this->normalizeRow($row);
        $meta = is_array($value['meta'] ?? null) ? $value['meta'] : [];
        $page = is_numeric($meta['page'] ?? null) ? (int) $meta['page'] : 1;
        $perPage = is_numeric($meta['per_page'] ?? null) ? (int) $meta['per_page'] : 20;
        $total = is_numeric($meta['total'] ?? null) ? (int) $meta['total'] : 0;
        $lastPage = is_numeric($meta['last_page'] ?? null) ? (int) $meta['last_page'] : 1;
        $normalizedMeta = [
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage,
        ];
        if (is_array($meta['facets'] ?? null)) {
            $facets = [];
            foreach ($meta['facets'] as $key => $facet) if (is_string($key)) $facets[$key] = $facet;
            $normalizedMeta['facets'] = $facets;
        }
        return ['data' => $data, 'meta' => $normalizedMeta];
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private function normalizeRow(array $value): array
    {
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) continue;
            if ($key === 'digital_assets' && is_array($item)) {
                $item = array_map(static function (mixed $asset): mixed {
                    if (!is_array($asset)) return $asset;
                    unset($asset['url'], $asset['access_key']);
                    return $asset;
                }, $item);
            }
            $row[$key] = $item;
        }
        return $row;
    }
}
