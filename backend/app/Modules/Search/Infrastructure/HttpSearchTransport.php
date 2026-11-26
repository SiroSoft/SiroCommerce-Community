<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use App\Modules\Search\Contracts\SearchTransport;
use Siro\Core\Env;
use Siro\Core\Http;
use Siro\Core\HttpResponse;

/** Common JSON boundary for OpenSearch, Elasticsearch, and Meilisearch-like APIs. */
final class HttpSearchTransport implements SearchTransport
{
    private readonly ?\Closure $request;

    public function __construct(?\Closure $request = null) { $this->request = $request; }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function search(string $index, array $body): array
    {
        $base = trim((string) Env::get('SEARCH_URL', ''));
        if ($base === '') throw new \RuntimeException('Search provider is configured without SEARCH_URL');
        if (filter_var($base, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \RuntimeException('Search provider is configured with an invalid SEARCH_URL');
        }
        $provider = strtolower(trim((string) Env::get('SEARCH_PROVIDER', 'opensearch')));
        if (!in_array($provider, ['opensearch', 'elasticsearch', 'meilisearch'], true)) {
            throw new \RuntimeException('Unsupported search provider');
        }
        $query = $body['query'] ?? null;
        if (!is_string($query) || trim($query) === '' || mb_strlen($query) > 80) {
            throw new \InvalidArgumentException('Search provider query must contain 1-80 characters');
        }
        if ($index === '') throw new \InvalidArgumentException('Search provider index is required');
        $apiKey = trim((string) Env::get('SEARCH_API_KEY', ''));
        if ($apiKey === '') throw new \RuntimeException('Search provider is configured without SEARCH_API_KEY');
        $meili = $provider === 'meilisearch';
        $path = $meili ? '/indexes/' . rawurlencode($index) . '/search' : '/' . rawurlencode($index) . '/_search';
        $page = max(1, $this->intValue($body['page'] ?? 1));
        $perPage = min(100, max(1, $this->intValue($body['per_page'] ?? 20)));
        if (isset($body['facets']) && !is_array($body['facets'])) throw new \InvalidArgumentException('Search provider facets must be an array');
        $facets = is_array($body['facets'] ?? null) ? array_values($body['facets']) : [];
        if (count($facets) > 10 || array_filter($facets, static fn (mixed $facet): bool => !is_string($facet) || !in_array($facet, ['status', 'state', 'store_id', 'channel_id', 'locale', 'currency', 'category', 'brand_id', 'type', 'tag'], true)) !== []) {
            throw new \InvalidArgumentException('Invalid search provider facets');
        }
        $filters = $body['filters'] ?? [];
        if (!is_array($filters)) throw new \InvalidArgumentException('Search provider filters must be an object');
        foreach ($filters as $key => $value) {
            if (!is_string($key) || !in_array($key, ['status', 'state', 'store_id', 'channel_id', 'locale', 'currency', 'category', 'brand_id', 'type', 'tag', 'from', 'to'], true) || (!is_scalar($value) && !is_array($value))) {
                throw new \InvalidArgumentException('Invalid search provider filters');
            }
        }
        $ranking = $this->ranking($body['ranking'] ?? null);
        $synonyms = $this->synonyms($body['synonyms'] ?? null);
        if (!is_bool($body['typo_tolerance'] ?? false)) throw new \InvalidArgumentException('Search provider typo_tolerance must be boolean');
        $typoTolerance = $body['typo_tolerance'];
        $aggregations = [];
        foreach ($facets as $facet) {
            if (is_string($facet) && $facet !== '') $aggregations[$facet] = ['terms' => ['field' => $facet]];
        }
        $payload = $meili
            ? ['q' => $query, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage, 'facets' => $facets, 'filter' => $this->meiliFilters($filters), 'typoTolerance' => $typoTolerance, 'ranking' => $ranking, 'synonyms' => $synonyms]
            : ['query' => ['bool' => ['must' => [['multi_match' => ['query' => $query, 'fields' => ['name^4', 'number^3', 'email', 'description'], 'fuzziness' => $typoTolerance ? 'AUTO' : 0]]], 'filter' => $this->elasticFilters($filters)]], 'from' => ($page - 1) * $perPage, 'size' => $perPage, 'aggs' => $aggregations, 'ranking' => $ranking, 'synonyms' => $synonyms, 'typo_tolerance' => $typoTolerance];
        Http::timeout((int) Env::get('SEARCH_TIMEOUT_SECONDS', '2'));
        $headers = ['Content-Type' => 'application/json', $meili ? 'X-Meili-API-Key' : 'Authorization' => $meili ? $apiKey : 'Bearer ' . $apiKey];
        $response = $this->request === null ? Http::post(rtrim($base, '/') . $path, json_encode($payload, JSON_THROW_ON_ERROR), $headers) : ($this->request)(rtrim($base, '/') . $path, json_encode($payload, JSON_THROW_ON_ERROR), $headers);
        if (!$response instanceof HttpResponse) throw new \RuntimeException('Search provider request failed');
        $decoded = $this->decode($response);
        if ($response->status() >= 400) throw new \RuntimeException('Search provider request failed');
        return $meili ? $this->normalizeMeilisearch($decoded, $page, $perPage) : $this->normalizeElastic($decoded, $page, $perPage);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, mixed>
     */
    private function meiliFilters(array $filters): array { $result = []; foreach ($filters as $key => $value) $result[] = is_array($value) ? [$key => $value] : $key . ' = ' . json_encode($value, JSON_THROW_ON_ERROR); return $result; }
    /**
     * @param array<string, mixed> $filters
     * @return array<int, mixed>
     */
    private function elasticFilters(array $filters): array { $result = []; foreach ($filters as $key => $value) $result[] = is_array($value) ? ['terms' => [$key => array_values($value)]] : (in_array($key, ['from', 'to'], true) ? ['range' => ['created_at' => [$key === 'from' ? 'gte' : 'lte' => $value]]] : ['term' => [$key => $value]]); return $result; }
    /** @return array<string, int> */
    private function ranking(mixed $value): array { if (!is_array($value) || array_diff(array_keys($value), ['text', 'exact', 'freshness', 'availability']) !== [] || array_diff(['text', 'exact', 'freshness', 'availability'], array_keys($value)) !== []) throw new \InvalidArgumentException('Invalid search provider ranking'); $result = []; foreach (['text', 'exact', 'freshness', 'availability'] as $key) { if (!is_numeric($value[$key]) || (int) $value[$key] < 0 || (int) $value[$key] > 100) throw new \InvalidArgumentException('Invalid search provider ranking'); $result[$key] = (int) $value[$key]; } if (array_sum($result) !== 100) throw new \InvalidArgumentException('Invalid search provider ranking'); return $result; }
    /** @return array<int, string> */
    private function synonyms(mixed $value): array { if (!is_array($value) || count($value) > 100) throw new \InvalidArgumentException('Invalid search provider synonyms'); $result = []; foreach ($value as $item) { if (!is_string($item) || trim($item) === '' || mb_strlen($item) > 80 || preg_match('/[\x00-\x1F\x7F]/', $item) === 1) throw new \InvalidArgumentException('Invalid search provider synonyms'); $result[] = $item; } return $result; }
    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalizeElastic(array $decoded, int $page, int $perPage): array { $hits = is_array($decoded['hits'] ?? null) ? $decoded['hits'] : []; $total = is_array($hits['total'] ?? null) ? ($hits['total']['value'] ?? 0) : ($hits['total'] ?? 0); $data = []; foreach ((array) ($hits['hits'] ?? []) as $hit) if (is_array($hit)) $data[] = is_array($hit['_source'] ?? null) ? $hit['_source'] : $hit; return ['data' => $data, 'meta' => $this->meta($page, $perPage, $total), 'facets' => $this->elasticFacets($this->aggregations($decoded['aggregations'] ?? null))]; }
    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalizeMeilisearch(array $decoded, int $page, int $perPage): array { $total = $decoded['estimatedTotalHits'] ?? count((array) ($decoded['hits'] ?? [])); return ['data' => array_values(array_filter((array) ($decoded['hits'] ?? []), 'is_array')), 'meta' => $this->meta($page, $perPage, $total), 'facets' => is_array($decoded['facetDistribution'] ?? null) ? $decoded['facetDistribution'] : []]; }
    /**
     * @param array<string, mixed> $aggregations
     * @return array<string, array<string, int>>
     */
    private function elasticFacets(array $aggregations): array { $facets = []; foreach ($aggregations as $name => $aggregation) { $facets[$name] = []; if (!is_array($aggregation)) continue; foreach ((array) ($aggregation['buckets'] ?? []) as $bucket) if (is_array($bucket) && is_scalar($bucket['key'] ?? null) && is_numeric($bucket['doc_count'] ?? null)) $facets[$name][(string) $bucket['key']] = (int) $bucket['doc_count']; } return $facets; }
    /** @return array<string, mixed> */
    private function aggregations(mixed $value): array { if (!is_array($value)) return []; $result = []; foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item; return $result; }
    /** @return array<string, mixed> */
    private function meta(int $page, int $perPage, mixed $total): array { $total = is_numeric($total) ? (int) $total : 0; return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]; }
    /** @return array<string, mixed> */
    private function decode(HttpResponse $response): array { $decoded = json_decode($response->body(), true); if (!is_array($decoded)) throw new \RuntimeException('Search provider request failed'); $result = []; foreach ($decoded as $key => $value) if (is_string($key)) $result[$key] = $value; return $result; }

    private function intValue(mixed $value): int { return is_numeric($value) ? (int) $value : 0; }
}
