<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Modules\Search\Contracts\SearchAdapter;
use App\Modules\Search\Contracts\SearchTransport;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;

/** Provider-neutral boundary with a bounded, PII-safe SQL fallback. */
final class NativeSearchAdapter implements SearchAdapter
{
    private const TYPES = ['catalog', 'orders', 'customers'];
    private const FILTERS = ['status', 'state', 'store_id', 'channel_id', 'locale', 'currency', 'category', 'brand_id', 'type', 'tag', 'from', 'to'];
    private const FACETS = ['status', 'state', 'store_id', 'channel_id', 'locale', 'currency', 'category', 'brand_id', 'type', 'tag'];
    private const RANKING_KEYS = ['text', 'exact', 'freshness', 'availability'];

    public function __construct(private readonly ?SearchTransport $transport = null) {}

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function search(array $request): array
    {
        $started = microtime(true);
        $query = trim($this->stringValue($request['q'] ?? ''));
        if ($query === '' || mb_strlen($query) > 80) throw new \InvalidArgumentException('Search query must contain 1-80 characters');
        $type = $this->stringValue($request['type'] ?? 'catalog');
        if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Unsupported search type');
        $rawFilters = $request['filters'] ?? [];
        if (!is_array($rawFilters)) throw new \InvalidArgumentException('Filters must be an object');
        /** @var array<string, mixed> $filters */
        $filters = [];
        foreach ($rawFilters as $key => $value) {
            if (!is_string($key) || !in_array($key, self::FILTERS, true)) throw new \InvalidArgumentException('Unsupported search filter: ' . (string) $key);
            $filters[$key] = $value;
        }
        $rawFacets = $request['facets'] ?? [];
        if (!is_array($rawFacets)) throw new \InvalidArgumentException('Facets must be an array');
        $facets = [];
        foreach ($rawFacets as $facet) {
            if (!is_string($facet) || !in_array($facet, self::FACETS, true)) throw new \InvalidArgumentException('Unsupported search facet');
            if (!in_array($facet, $facets, true)) $facets[] = $facet;
        }
        if (count($facets) > 4) throw new \InvalidArgumentException('Too many search facets');
        $page = max(1, $this->intValue($request['page'] ?? 1));
        $perPage = min(100, max(1, $this->intValue($request['per_page'] ?? 20)));
        foreach (['store_id', 'channel_id', 'locale', 'currency'] as $scopeKey) {
            if (array_key_exists($scopeKey, $request) && $request[$scopeKey] !== null && $request[$scopeKey] !== '') {
                $filters[$scopeKey] = $request[$scopeKey];
            }
        }
        $body = ['query' => $query, 'type' => $type, 'filters' => $filters, 'facets' => $facets, 'page' => $page, 'per_page' => $perPage, 'typo_tolerance' => Env::bool('SEARCH_TYPO_TOLERANCE', true), 'synonyms' => $this->synonyms(), 'ranking' => $this->ranking()];
        if ($this->transport !== null) {
            $result = $this->transport->search($this->index($type), $body);
            $result = $this->decorate($result, $type, 'provider');
            $this->recordAnalytics($type, $query, count((array) ($result['data'] ?? [])), $started);
            return $result;
        }
        $result = $this->decorate($this->native($query, $type, $filters, $facets, $page, $perPage), $type, 'native');
        $this->recordAnalytics($type, $query, count((array) ($result['data'] ?? [])), $started);
        return $result;
    }

    public function status(): array
    {
        $version = $this->stringValue(Env::get('SEARCH_INDEX_VERSION', 'catalog-v1'));
        $provider = strtolower(trim($this->stringValue(Env::get('SEARCH_PROVIDER', 'native'))));
        $checkpoint = null;
        try {
            $statement = Database::connection()->prepare('SELECT * FROM search_index_checkpoints WHERE index_name = ?');
            $statement->execute([$version]);
            $row = $statement->fetch();
            $checkpoint = is_array($row) ? $row : null;
        } catch (\Throwable) { /* pre-migration readiness remains explicit */ }
        $updated = is_array($checkpoint) ? strtotime($this->stringValue($checkpoint['updated_at'] ?? '')) : false;
        $lag = $updated === false ? 0 : max(0, time() - $updated);
        $stale = $lag > $this->intValue(Env::get('SEARCH_MAX_LAG_SECONDS', '20'));
        $checkpointReady = is_array($checkpoint) && in_array($this->stringValue($checkpoint['state'] ?? ''), ['ready', 'completed'], true);
        $configured = $this->providerConfigurationReady($provider, $version);
        $deadLetters = 0;
        $queued = 0;
        try {
            $deadLetters = $this->count("SELECT COUNT(*) FROM search_index_dead_letters WHERE (index_name = ? OR index_name LIKE ?) AND resolved_at IS NULL", [$version, $version . '-%']);
            $queued = $this->count("SELECT COUNT(*) FROM commerce_jobs WHERE queue = 'search' AND state IN ('queued', 'running')");
        } catch (\Throwable) { /* optional evidence tables are reflected as not ready */ }
        return ['provider' => $provider, 'mode' => $provider === 'native' ? 'native' : 'provider', 'configured' => $configured, 'index_version' => $version, 'checkpoint' => $checkpoint, 'lag_seconds' => $lag, 'stale' => $stale, 'queued_reindexes' => $queued, 'unresolved_dead_letters' => $deadLetters, 'ready' => $configured && ($provider === 'native' ? true : $checkpointReady) && !$stale && $deadLetters === 0];
    }

    private function providerConfigurationReady(string $provider, string $index): bool
    {
        if ($provider === 'native') return $index !== '';
        if (!in_array($provider, ['opensearch', 'elasticsearch', 'meilisearch'], true) || $index === '') return false;
        $url = trim((string) Env::get('SEARCH_URL', ''));
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && trim((string) Env::get('SEARCH_API_KEY', '')) !== '';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function enqueueReindex(array $request): array
    {
        $type = $this->stringValue($request['type'] ?? 'all');
        if ($type !== 'all' && !in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Unsupported reindex type');
        $version = (string) Env::get('SEARCH_INDEX_VERSION', 'catalog-v1');
        $idempotencyKey = 'search-reindex:' . $version . ':' . $type;
        $db = Database::connection();
        $existing = $db->prepare('SELECT id, state FROM commerce_jobs WHERE idempotency_key = ?');
        $existing->execute([$idempotencyKey]);
        $row = $existing->fetch();
        if (is_array($row) && isset($row['id'])) return ['id' => $this->stringValue($row['id']), 'state' => $this->stringValue($row['state'] ?? 'queued'), 'type' => $type, 'index_version' => $version, 'idempotent' => true];
        $id = Ids::new();
        $payload = json_encode(['id' => $id, 'type' => $type, 'index_version' => $version], JSON_THROW_ON_ERROR);
        $statement = $db->prepare('INSERT INTO commerce_jobs (id, queue, name, idempotency_key, payload_json, state, attempts, max_attempts, available_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        try {
            $statement->execute([$id, 'search', 'search.reindex', $idempotencyKey, $payload, 'queued', 0, 5, date('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            $existing->execute([$idempotencyKey]);
            $row = $existing->fetch();
            if (!is_array($row) || !isset($row['id'])) throw $e;
            return ['id' => $this->stringValue($row['id']), 'state' => $this->stringValue($row['state'] ?? 'queued'), 'type' => $type, 'index_version' => $version, 'idempotent' => true];
        }
        $checkpointExists = $db->prepare('SELECT index_name FROM search_index_checkpoints WHERE index_name = ?');
        $checkpointExists->execute([$version]);
        if ($checkpointExists->fetch() !== false) {
            $checkpoint = $db->prepare('UPDATE search_index_checkpoints SET cursor = ?, version = ?, state = ?, processed = ?, failed = ?, updated_at = ? WHERE index_name = ?');
            $checkpoint->execute([null, $version, 'queued', 0, 0, date('Y-m-d H:i:s'), $version]);
        } else {
            $checkpoint = $db->prepare('INSERT INTO search_index_checkpoints (index_name, cursor, version, state, processed, failed, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $checkpoint->execute([$version, null, $version, 'queued', 0, 0, date('Y-m-d H:i:s')]);
        }
        return ['id' => $id, 'state' => 'queued', 'type' => $type, 'index_version' => $version, 'idempotent' => false];
    }

    /** Called by the queue worker after each bounded batch. */
    public function checkpoint(string $index, ?string $cursor, string $state, int $processed, int $failed): void
    {
        $db = Database::connection();
        $statement = $db->prepare('UPDATE search_index_checkpoints SET cursor = ?, state = ?, processed = ?, failed = ?, updated_at = ? WHERE index_name = ?');
        $statement->execute([$cursor, $state, max(0, $processed), max(0, $failed), date('Y-m-d H:i:s'), $index]);
    }

    public function deadLetter(string $index, string $documentId, string $error): void
    {
        $statement = Database::connection()->prepare('INSERT INTO search_index_dead_letters (id, index_name, document_id, error, created_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([Ids::new(), $index, $documentId, substr($error, 0, 2000), date('Y-m-d H:i:s')]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function native(string $term, string $type, array $filters, array $facets, int $page, int $perPage): array
    {
        $like = '%' . $term . '%';
        if ($type === 'catalog') $builder = Product::query()->select(['id', 'name', 'slug', 'status', 'store_id', 'channel_id', 'locale', 'currency', 'category_id', 'brand_id', 'type'])->whereRaw('(name LIKE :search OR description LIKE :search OR slug LIKE :search)', ['search' => $like]);
        elseif ($type === 'orders') $builder = Order::query()->select(['id', 'number', 'state', 'grand_total_minor', 'currency', 'created_at'])->where('number', 'LIKE', $like);
        else $builder = Customer::query()->select(['id', 'name', 'email', 'created_at'])->whereRaw('(email LIKE :search OR name LIKE :search)', ['search' => $like]);
        foreach (['status', 'state', 'store_id', 'channel_id', 'locale', 'currency', 'category_id', 'brand_id', 'type'] as $filter) if (isset($filters[$filter]) && $filters[$filter] !== '') $builder->where($filter, $this->stringValue($filters[$filter]));
        if (isset($filters['category']) && $filters['category'] !== '') $builder->where('category_id', $this->stringValue($filters['category']));
        if (isset($filters['tag']) && $filters['tag'] !== '') $builder->where('tags_json', 'LIKE', '%' . $this->stringValue($filters['tag']) . '%');
        if (isset($filters['from'], $filters['to'])) $builder->where('created_at', '>=', $this->stringValue($filters['from']))->where('created_at', '<=', $this->stringValue($filters['to']));
        /** @var array<string, mixed> $result */
        $result = $builder->orderBy('id')->cache(20)->paginate($perPage, $page);
        $rows = [];
        foreach ((array) ($result['data'] ?? []) as $row) {
            $data = Arr::row($row);
            if ($type === 'customers') $rows[] = ['id' => Arr::str($data, 'id'), 'name' => Arr::str($data, 'name'), 'email_masked' => $this->maskEmail(Arr::str($data, 'email'))];
            else $rows[] = $data;
        }
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : ['page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'last_page' => 1];
        $facetData = [];
        if ($facets !== []) {
            $facetColumns = array_values(array_unique(array_map(static fn (string $facet): string => $facet === 'tag' ? 'tags_json' : ($facet === 'category' ? 'category_id' : $facet), $facets)));
            $facetQuery = Product::query()->select($facetColumns)->whereRaw('(name LIKE :search OR description LIKE :search OR slug LIKE :search)', ['search' => $like]);
            foreach (['store_id', 'channel_id', 'locale', 'currency'] as $scopeKey) if (isset($filters[$scopeKey]) && $filters[$scopeKey] !== '') $facetQuery->where($scopeKey, $this->stringValue($filters[$scopeKey]));
            foreach ($facets as $facet) {
                $counts = [];
                foreach ($facetQuery->get() as $facetRow) {
                    $facetRowData = Arr::row($facetRow);
                    $value = Arr::str($facetRowData, $facet === 'tag' ? 'tags_json' : ($facet === 'category' ? 'category_id' : $facet));
                    $values = $facet === 'tag' ? json_decode($value, true) : [$value];
                    if (!is_array($values)) $values = [];
                    foreach ($values as $item) if (is_string($item) && $item !== '') $counts[$item] = ($counts[$item] ?? 0) + 1;
                }
                ksort($counts);
                $facetData[$facet] = $counts;
            }
        }
        return ['data' => $rows, 'meta' => $meta, 'facets' => $facetData];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function decorate(array $result, string $type, string $mode): array
    {
        $result['type'] = $type;
        $result['mode'] = $mode;
        $result['index'] = $this->status();
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $result['facets'] = is_array($result['facets'] ?? null) ? $result['facets'] : ['type' => [$type => is_numeric($meta['total'] ?? null) ? (int) $meta['total'] : count($data)]];
        return $result;
    }

    private function index(string $type): string { return (string) Env::get('SEARCH_INDEX_VERSION', 'catalog-v1') . '-' . $type; }
    private function recordAnalytics(string $type, string $query, int $count, float $started): void
    {
        try {
            $statement = Database::connection()->prepare('INSERT INTO search_analytics (id, query_hash, index_name, result_count, latency_ms, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $statement->execute([Ids::new(), hash('sha256', mb_strtolower($query)), $this->index($type), $count, (int) round((microtime(true) - $started) * 1000), date('Y-m-d H:i:s')]);
        } catch (\Throwable) { /* analytics must never make search unavailable */ }
    }
    /** @return array<int, string> */
    private function synonyms(): array
    {
        $raw = trim((string) Env::get('SEARCH_SYNONYMS', ''));
        if ($raw === '') return [];
        $synonyms = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== ''));
        if (count($synonyms) > 100) throw new \InvalidArgumentException('Too many search synonyms');
        foreach ($synonyms as $synonym) {
            if (mb_strlen($synonym) > 80 || preg_match('/[\x00-\x1F\x7F]/', $synonym) === 1) throw new \InvalidArgumentException('Invalid search synonym');
        }
        return $synonyms;
    }
    /** @return array<string, int> */
    private function ranking(): array
    {
        $raw = json_decode((string) Env::get('SEARCH_RANKING', '{}'), true);
        $defaults = ['text' => 60, 'exact' => 25, 'freshness' => 10, 'availability' => 5];
        $configured = trim((string) Env::get('SEARCH_RANKING', ''));
        if ($configured === '' || $configured === '{}') return $defaults;
        if (!is_array($raw) || array_diff(array_keys($raw), self::RANKING_KEYS) !== [] || array_diff(self::RANKING_KEYS, array_keys($raw)) !== []) throw new \InvalidArgumentException('Search ranking must define text, exact, freshness and availability');
        $ranking = [];
        foreach (self::RANKING_KEYS as $key) {
            if (!is_int($raw[$key]) && !is_float($raw[$key]) && !(is_string($raw[$key]) && ctype_digit($raw[$key]))) throw new \InvalidArgumentException('Search ranking weights must be non-negative integers');
            $ranking[$key] = (int) $raw[$key];
            if ($ranking[$key] < 0 || $ranking[$key] > 100) throw new \InvalidArgumentException('Search ranking weights must be 0-100');
        }
        if (array_sum($ranking) !== 100) throw new \InvalidArgumentException('Search ranking weights must total 100');
        return $ranking;
    }
    private function stringValue(mixed $value): string { return is_scalar($value) ? (string) $value : ''; }
    private function intValue(mixed $value): int { return is_numeric($value) ? (int) $value : 0; }
    /** @param array<int, mixed> $params */
    private function count(string $sql, array $params = []): int
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }
    private function maskEmail(string $email): string { $parts = explode('@', $email, 2); return count($parts) === 2 ? substr($parts[0], 0, 1) . '***@' . $parts[1] : '***'; }
}
