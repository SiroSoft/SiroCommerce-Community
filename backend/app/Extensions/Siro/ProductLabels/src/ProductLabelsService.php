<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductLabels;

use App\Modules\Dashboard\Contracts\DashboardBiReader;
use App\Modules\Extension\Application\ExtensionScope;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Container;
use Siro\Core\Database;

/**
 * Store/channel scoped product-label rules + the rules engine that matches
 * them against a product.
 *
 * Every read and write filters by ExtensionScope; a row read by id is checked
 * with assertScoped before it is trusted. Mutations are audited into the
 * extension's own `ext_product_label_audit` table (append-only).
 *
 * Product attributes come from the core catalog contract
 * (`CatalogService::show`) resolved via the container — the extension never
 * reads core tables or instantiates core models directly. Best sellers come
 * from `DashboardBiReader::topProducts` (top 5 by units sold in the last 90
 * days, scope-filtered), resolved lazily and only when a rule needs it.
 */
final class ProductLabelsService
{
    /** @var list<string> */
    public const KINDS = ['new', 'sale', 'best_seller', 'custom'];

    public const MAX_TEXT_LENGTH = 80;
    public const MAX_LABELS_PER_PRODUCT = 3;
    public const MAX_CONDITIONS_BYTES = 2000;
    public const DEFAULT_NEW_DAYS = 30;
    public const BEST_SELLER_WINDOW_DAYS = 90;

    private const RULE_COLUMNS = 'id, store_id, channel_id, rule_key, text, kind, conditions_json, priority, active, idempotency_key, created_at, updated_at';

    /** @var callable(CommerceScope, string): ?array<string, mixed> */
    private $productResolver;

    /** @var callable(CommerceScope): list<string> */
    private $bestSellerResolver;

    /**
     * @param callable(CommerceScope, string): ?array<string, mixed>|null $productResolver
     * @param callable(CommerceScope): list<string>|null $bestSellerResolver
     */
    public function __construct(?callable $productResolver = null, ?callable $bestSellerResolver = null)
    {
        $this->productResolver = $productResolver ?? static function (CommerceScope $scope, string $slug): ?array {
            try {
                $catalog = Container::getInstance()->make(\App\Modules\Catalog\Application\CatalogService::class);
            } catch (\Throwable) {
                return null;
            }
            if (!$catalog instanceof \App\Modules\Catalog\Application\CatalogService) {
                return null;
            }
            $row = $catalog->show($slug, $scope);
            return is_array($row) ? $row : null;
        };
        $this->bestSellerResolver = $bestSellerResolver ?? static function (CommerceScope $scope): array {
            try {
                $reader = Container::getInstance()->make(DashboardBiReader::class);
            } catch (\Throwable) {
                return [];
            }
            if (!$reader instanceof DashboardBiReader) {
                return [];
            }
            $to = date('Y-m-d');
            $from = date('Y-m-d', time() - self::BEST_SELLER_WINDOW_DAYS * 86400);
            $rows = $reader->topProducts($from, $to, $scope);
            $ids = [];
            foreach ($rows as $row) {
                if ($row['product_id'] !== '') {
                    $ids[] = $row['product_id'];
                }
            }
            return $ids;
        };
    }

    /** @return list<array<string, mixed>> */
    public function list(CommerceScope $scope): array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->rows(
            'SELECT ' . self::RULE_COLUMNS . ' FROM ext_product_label_rules WHERE ' . $where
            . ' ORDER BY priority ASC, created_at ASC, id ASC',
            $params
        );
    }

    /** @return array{total: int, active: int, by_kind: array<string, int>} */
    public function stats(CommerceScope $scope): array
    {
        $rules = $this->list($scope);
        $byKind = array_fill_keys(self::KINDS, 0);
        $active = 0;
        foreach ($rules as $rule) {
            if ($rule['active'] === true) {
                $active++;
            }
            $kind = is_string($rule['kind'] ?? null) ? $rule['kind'] : 'custom';
            if (array_key_exists($kind, $byKind)) {
                $byKind[$kind]++;
            }
        }
        return ['total' => count($rules), 'active' => $active, 'by_kind' => $byKind];
    }

    /** @return array<string, mixed>|null */
    public function find(CommerceScope $scope, string $id): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $rows = $this->rows(
            'SELECT ' . self::RULE_COLUMNS . ' FROM ext_product_label_rules WHERE ' . $where . ' AND id = ? LIMIT 1',
            array_merge($params, [$id])
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        ExtensionScope::assertScoped($row, $scope);
        return $row;
    }

    /**
     * Create a rule. A replay with the same scoped Idempotency-Key returns the
     * canonical row instead of duplicating it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(CommerceScope $scope, array $data, ?string $idempotencyKey = null, string $actor = 'system'): array
    {
        $normalized = $this->normalize($data, true);
        $ruleKey = $normalized['rule_key'] ?? null;
        if (!is_string($ruleKey)) {
            throw new \InvalidArgumentException('rule_key is required');
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->findByIdempotencyKey($scope, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($this->findByKey($scope, $ruleKey) !== null) {
            throw new \InvalidArgumentException('rule_key already exists in this store/channel');
        }
        $now = date('Y-m-d H:i:s');
        $id = Ids::new();
        $payload = ExtensionScope::merge(array_merge([
            'id' => $id,
            'rule_key' => $ruleKey,
            'text' => $normalized['text'],
            'kind' => $normalized['kind'],
            'conditions_json' => json_encode($normalized['conditions'], JSON_THROW_ON_ERROR),
            'priority' => $normalized['priority'],
            'active' => $normalized['active'] ? 1 : 0,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->scopePassthrough($data)), $scope);
        try {
            Database::table('ext_product_label_rules')->insert($payload);
        } catch (\Throwable $exception) {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                /** @var array<string, mixed>|null $winner */
                $winner = $this->findByIdempotencyKey($scope, $idempotencyKey);
                if ($winner !== null) {
                    return $winner;
                }
            }
            /** @var array<string, mixed>|null $duplicate */
            $duplicate = $this->findByKey($scope, $ruleKey);
            if ($duplicate !== null) {
                throw new \InvalidArgumentException('rule_key already exists in this store/channel', 0, $exception);
            }
            throw new \RuntimeException('Product label rule could not be saved', 0, $exception);
        }
        $saved = $this->find($scope, $id);
        if ($saved === null) {
            throw new \RuntimeException('Product label rule could not be saved');
        }
        $this->audit($scope, $actor, 'rule.created', $saved);
        return $saved;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(CommerceScope $scope, string $id, array $data, string $actor = 'system'): array
    {
        $existing = $this->find($scope, $id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Product label rule not found');
        }
        if (array_key_exists('rule_key', $data) && is_string($data['rule_key']) && $data['rule_key'] !== $existing['rule_key']) {
            throw new \InvalidArgumentException('rule_key is immutable');
        }
        $normalized = $this->normalize($data, false);
        if ($normalized === []) {
            throw new \InvalidArgumentException('Nothing to update');
        }
        $sets = [];
        $params = [];
        foreach (['text', 'kind', 'priority', 'active'] as $field) {
            if (!array_key_exists($field, $normalized)) {
                continue;
            }
            $sets[] = $field . ' = ?';
            $params[] = $field === 'active' ? ($normalized[$field] ? 1 : 0) : $normalized[$field];
        }
        if (array_key_exists('conditions', $normalized)) {
            $sets[] = 'conditions_json = ?';
            $params[] = json_encode($normalized['conditions'], JSON_THROW_ON_ERROR);
        }
        $sets[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        [$where, $scopeParams] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'UPDATE ext_product_label_rules SET ' . implode(', ', $sets) . ' WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Product label rule could not be updated');
        }
        $stmt->execute(array_merge($params, $scopeParams, [$id]));
        $saved = $this->find($scope, $id);
        if ($saved === null) {
            throw new \RuntimeException('Product label rule could not be updated');
        }
        $this->audit($scope, $actor, 'rule.updated', $saved);
        return $saved;
    }

    public function delete(CommerceScope $scope, string $id, string $actor = 'system'): bool
    {
        $existing = $this->find($scope, $id);
        if ($existing === null) {
            return false;
        }
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'DELETE FROM ext_product_label_rules WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Product label rule could not be deleted');
        }
        $stmt->execute(array_merge($params, [$id]));
        $this->audit($scope, $actor, 'rule.deleted', $existing);
        return true;
    }

    /**
     * Labels for one product, in display order (priority asc, then oldest
     * rule first), capped at MAX_LABELS_PER_PRODUCT.
     *
     * @param array<string, mixed> $product Product attributes (CatalogService row shape).
     * @return list<array{rule_key: string, text: string, kind: string, priority: int}>
     */
    public function labelsForProduct(CommerceScope $scope, array $product): array
    {
        $rules = array_values(array_filter(
            $this->list($scope),
            static fn (array $rule): bool => $rule['active'] === true
        ));
        $needsBestSellers = false;
        $effective = [];
        foreach ($rules as $rule) {
            $conditions = $this->effectiveConditions($rule);
            $effective[] = ['rule' => $rule, 'conditions' => $conditions];
            if (($conditions['best_seller'] ?? null) === true) {
                $needsBestSellers = true;
            }
        }
        $bestSellerIds = $needsBestSellers ? ($this->bestSellerResolver)($scope) : [];
        $matched = [];
        foreach ($effective as $item) {
            $rule = $item['rule'];
            if (RuleEvaluator::matches($item['conditions'], $product, $bestSellerIds)) {
                $matched[] = [
                    'rule_key' => $this->stringValue($rule['rule_key'] ?? ''),
                    'text' => $this->stringValue($rule['text'] ?? ''),
                    'kind' => $this->stringValue($rule['kind'] ?? 'custom'),
                    'priority' => $this->intValue($rule['priority'] ?? null) ?? 100,
                ];
            }
            if (count($matched) >= self::MAX_LABELS_PER_PRODUCT) {
                break;
            }
        }
        return $matched;
    }

    /**
     * Resolve a product by slug through the catalog contract and evaluate
     * labels for it. Returns null when the product does not exist in scope.
     *
     * @return array{product_id: string, product_slug: string, labels: list<array{rule_key: string, text: string, kind: string, priority: int}>}|null
     */
    public function labelsForSlug(CommerceScope $scope, string $slug): ?array
    {
        $product = ($this->productResolver)($scope, $slug);
        if ($product === null || !isset($product['id']) || !is_string($product['id']) || $product['id'] === '') {
            return null;
        }
        return [
            'product_id' => $product['id'],
            'product_slug' => $slug,
            'labels' => $this->labelsForProduct($scope, $product),
        ];
    }

    /**
     * Kind-implied defaults: a rule's kind names its canonical condition.
     * Explicit conditions always win over the default.
     *
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function effectiveConditions(array $rule): array
    {
        $conditions = [];
        $raw = $rule['conditions'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($key)) {
                    $conditions[$key] = $value;
                }
            }
        }
        switch ($rule['kind'] ?? 'custom') {
            case 'new':
                if (!array_key_exists('is_new_days', $conditions)) {
                    $conditions['is_new_days'] = self::DEFAULT_NEW_DAYS;
                }
                break;
            case 'sale':
                if (!array_key_exists('on_sale', $conditions) && !array_key_exists('discount_min_pct', $conditions)) {
                    $conditions['on_sale'] = true;
                }
                break;
            case 'best_seller':
                if (!array_key_exists('best_seller', $conditions) && !array_key_exists('product_ids', $conditions)) {
                    $conditions['best_seller'] = true;
                }
                break;
        }
        return $conditions;
    }

    /**
     * Pass caller-supplied scope columns through to ExtensionScope::merge so
     * a mismatched store_id/channel_id is rejected instead of silently
     * dropped (EXTENSION_SCOPING §3). Matching values are accepted.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function scopePassthrough(array $data): array
    {
        $out = [];
        foreach (['store_id', 'channel_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $out[$column] = $data[$column];
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function findByKey(CommerceScope $scope, string $ruleKey): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $rows = $this->rows(
            'SELECT ' . self::RULE_COLUMNS . ' FROM ext_product_label_rules WHERE ' . $where . ' AND rule_key = ? LIMIT 1',
            array_merge($params, [$ruleKey])
        );
        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotencyKey(CommerceScope $scope, string $key): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $rows = $this->rows(
            'SELECT ' . self::RULE_COLUMNS . ' FROM ext_product_label_rules WHERE ' . $where . ' AND idempotency_key = ? LIMIT 1',
            array_merge($params, [$key])
        );
        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $rule */
    private function audit(CommerceScope $scope, string $actor, string $action, array $rule): void
    {
        $payload = ExtensionScope::merge([
            'id' => Ids::new(),
            'actor' => mb_substr(trim($actor) === '' ? 'system' : trim($actor), 0, 160),
            'action' => $action,
            'rule_id' => is_string($rule['id'] ?? null) ? $rule['id'] : null,
            'rule_key' => is_string($rule['rule_key'] ?? null) ? $rule['rule_key'] : null,
            'payload_json' => json_encode([
                'rule_key' => $rule['rule_key'] ?? null,
                'text' => $rule['text'] ?? null,
                'kind' => $rule['kind'] ?? null,
                'priority' => $rule['priority'] ?? null,
                'active' => $rule['active'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ], $scope);
        Database::table('ext_product_label_audit')->insert($payload);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = Database::connection()->prepare($sql);
        if (!$stmt instanceof \PDOStatement) {
            return [];
        }
        $stmt->execute($params);
        /** @var array<int, mixed> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $clean = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $clean[$key] = $value;
                    }
                }
                $out[] = $this->present($clean);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, bool $isCreate): array
    {
        $out = [];
        if ($isCreate || array_key_exists('rule_key', $data)) {
            $key = $data['rule_key'] ?? null;
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key) !== 1) {
                throw new \InvalidArgumentException('rule_key must be lowercase alphanumeric with dashes/underscores (max 64)');
            }
            $out['rule_key'] = $key;
        }
        if ($isCreate || array_key_exists('text', $data)) {
            $text = isset($data['text']) && is_string($data['text']) ? trim($data['text']) : '';
            if ($text === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH || str_contains($text, '<') || str_contains($text, '>')) {
                throw new \InvalidArgumentException('text must be 1-' . self::MAX_TEXT_LENGTH . ' characters of plain text (no < or >)');
            }
            $out['text'] = $text;
        }
        if ($isCreate || array_key_exists('kind', $data)) {
            $kind = $data['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException('kind must be one of: ' . implode(', ', self::KINDS));
            }
            $out['kind'] = $kind;
        }
        if ($isCreate || array_key_exists('conditions', $data)) {
            $out['conditions'] = $this->normalizeConditions($data['conditions'] ?? null);
        }
        if ($isCreate || array_key_exists('priority', $data)) {
            $priority = $this->intValue($data['priority'] ?? null);
            if ($priority === null || $priority < 0 || $priority > 1000) {
                throw new \InvalidArgumentException('priority must be an integer between 0 and 1000');
            }
            $out['priority'] = $priority;
        }
        if ($isCreate || array_key_exists('active', $data)) {
            $active = $this->boolValue($data['active'] ?? null);
            if ($active === null) {
                throw new \InvalidArgumentException('active must be a boolean');
            }
            $out['active'] = $active;
        }
        if ($isCreate) {
            $out['conditions'] = $out['conditions'] ?? [];
            $out['priority'] = $out['priority'] ?? 100;
            $out['active'] = $out['active'] ?? true;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConditions(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('conditions must be a JSON object');
            }
            $raw = $decoded;
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('conditions must be a JSON object');
        }
        $encoded = json_encode($raw, JSON_THROW_ON_ERROR);
        if (mb_strlen($encoded) > self::MAX_CONDITIONS_BYTES) {
            throw new \InvalidArgumentException('conditions must be at most ' . self::MAX_CONDITIONS_BYTES . ' bytes');
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !in_array($key, RuleEvaluator::CONDITION_KEYS, true)) {
                throw new \InvalidArgumentException('Unknown condition: ' . (is_string($key) ? $key : ''));
            }
            $out[$key] = $this->normalizeCondition($key, $value);
        }
        return $out;
    }

    private function normalizeCondition(string $key, mixed $value): mixed
    {
        switch ($key) {
            case 'is_new_days':
                $int = $this->intValue($value);
                if ($int === null || $int < 1 || $int > 365) {
                    throw new \InvalidArgumentException('is_new_days must be an integer between 1 and 365');
                }
                return $int;
            case 'on_sale':
            case 'best_seller':
                $bool = $this->boolValue($value);
                if ($bool === null) {
                    throw new \InvalidArgumentException($key . ' must be a boolean');
                }
                return $bool;
            case 'discount_min_pct':
            case 'rating_min':
                $float = $this->floatValue($value);
                $max = $key === 'discount_min_pct' ? 100.0 : 5.0;
                if ($float === null || $float < 0 || $float > $max) {
                    throw new \InvalidArgumentException($key . ' must be a number between 0 and ' . $max);
                }
                return $float;
            case 'category':
            case 'tag':
                return $this->normalizeStringList($key, $value, 64);
            case 'product_ids':
                return $this->normalizeStringList($key, $value, 64, 100);
            case 'price_min_minor':
            case 'price_max_minor':
            case 'stock_max':
                $int = $this->intValue($value);
                if ($int === null || $int < 0) {
                    throw new \InvalidArgumentException($key . ' must be a non-negative integer');
                }
                return $int;
            default:
                throw new \InvalidArgumentException('Unknown condition: ' . $key);
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(string $key, mixed $value, int $maxLength, int $maxItems = 50): array
    {
        if (is_string($value)) {
            $list = [$value];
        } elseif (is_array($value)) {
            $list = array_values($value);
        } else {
            throw new \InvalidArgumentException($key . ' must be a string or a non-empty list of strings');
        }
        if ($list === []) {
            throw new \InvalidArgumentException($key . ' must be a string or a non-empty list of strings');
        }
        if (count($list) > $maxItems) {
            throw new \InvalidArgumentException($key . ' must list at most ' . $maxItems . ' values');
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item) || trim($item) === '' || mb_strlen($item) > $maxLength) {
                throw new \InvalidArgumentException($key . ' values must be non-empty strings (max ' . $maxLength . ')');
            }
            $out[] = trim($item);
        }
        return $out;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        return null;
    }

    private function floatValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        return null;
    }

    private function boolValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false'], true)) {
                return false;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $conditions = [];
        $raw = $row['conditions_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key)) {
                        $conditions[$key] = $value;
                    }
                }
            }
        }
        return [
            'id' => $this->stringValue($row['id'] ?? ''),
            'store_id' => $this->stringValue($row['store_id'] ?? ''),
            'channel_id' => $this->stringValue($row['channel_id'] ?? ''),
            'rule_key' => $this->stringValue($row['rule_key'] ?? ''),
            'text' => $this->stringValue($row['text'] ?? ''),
            'kind' => $this->stringValue($row['kind'] ?? 'custom'),
            'conditions' => $conditions,
            'priority' => $this->intValue($row['priority'] ?? null) ?? 100,
            'active' => $this->boolValue($row['active'] ?? null) ?? false,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return '';
    }
}
