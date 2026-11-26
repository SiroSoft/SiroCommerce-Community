<?php

declare(strict_types=1);

namespace App\Modules\Headless\Application;

use App\Modules\Catalog\Application\CatalogService;
use App\Modules\Cms\Application\CmsService;
use App\Modules\Headless\Contracts\HeadlessQueryReader;
use App\Modules\Headless\Contracts\OrderOwnerReader;
use App\Modules\Headless\Domain\HeadlessException;
use App\Modules\Headless\Infrastructure\GraphQlParser;
use App\Modules\Headless\Infrastructure\HeadlessSchema;
use App\Modules\Order\Application\OrderQueryService;
use App\Support\CommerceScope;

final class HeadlessQueryService implements HeadlessQueryReader
{
    public function __construct(private readonly CatalogService $catalog, private readonly CmsService $cms, private readonly OrderQueryService $orders, private readonly GraphQlParser $parser, private readonly OrderOwnerReader $ownerReader)
    {
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function execute(string $source, ?array $user, array $variables = [], ?CommerceScope $scope = null): array
    {
        if (count($variables) > 20) throw new HeadlessException('REQUEST_LIMIT', 'Too many variables.');
        $query = $this->parser->parse($source, $variables);
        /** @var array<int, array<string, mixed>> $fields */
        $fields = is_array($query['selection'] ?? null) ? $query['selection'] : [];
        $complexity = $this->complexity($fields, 1);
        if ($complexity > 80) throw new HeadlessException('COMPLEXITY_LIMIT', 'Query complexity exceeds the maximum of 80.');
        $data = [];
        foreach ($fields as $field) {
            $name = is_string($field['name'] ?? null) ? $field['name'] : '';
            /** @var array<string, string|int> $args */
            $args = is_array($field['args'] ?? null) ? $field['args'] : [];
            /** @var array<int, array<string, mixed>> $children */
            $children = is_array($field['children'] ?? null) ? $field['children'] : [];
            $key = is_string($field['alias'] ?? null) ? $field['alias'] : $name;
            $data[$key] = $this->resolveRoot($name, $args, $children, $user, $scope);
        }
        return $data;
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function complexity(array $fields, int $depth): int
    {
        if ($depth > 8) throw new HeadlessException('DEPTH_LIMIT', 'Query depth exceeds the maximum of 8.');
        $total = 0;
        foreach ($fields as $field) {
            $children = normalizeNodes($field['children'] ?? []);
            $total += 1 + ($children === [] ? 0 : $this->complexity($children, $depth + 1));
        }
        return $total;
    }

    /**
     * @param array<string, string|int> $args
     * @param array<int, array<string, mixed>> $children
     * @param array<string, mixed>|null $user
     */
    private function resolveRoot(string $name, array $args, array $children, ?array $user, ?CommerceScope $scope): mixed
    {
        $schema = HeadlessSchema::definition();
        /** @var array<int, string> $queryFields */
        $queryFields = $schema['query'];
        if (!in_array($name, $queryFields, true)) throw new HeadlessException('VALIDATION_ERROR', 'Unknown query field: ' . $name . '.');
        if ($name === 'product') {
            if (!isset($args['slug']) || !is_string($args['slug']) || $args['slug'] === '') throw new HeadlessException('VALIDATION_ERROR', 'Product slug is required.');
            $row = $this->catalog->show($args['slug'], $scope);
            return $row === null ? null : $this->project($row, $children, 'product');
        }
        if ($name === 'products') {
            $page = max((int) ($args['page'] ?? 1), 1); $perPage = min(max((int) ($args['perPage'] ?? 20), 1), 100);
            $result = $this->catalog->listActive($page, $perPage, $scope);
            $result['nodes'] = array_map(fn (array $row): array => $this->project($row, childrenForNodes($children), 'product'), $result['data']);
            unset($result['data']);
            return $this->project($result, $children, 'products');
        }
        if ($name === 'menu') {
            if (!isset($args['key']) || !is_string($args['key']) || !preg_match('/^[a-z0-9_-]{1,64}$/', $args['key'])) throw new HeadlessException('VALIDATION_ERROR', 'Menu key is required.');
            $locale = isset($args['locale']) && is_string($args['locale']) ? $args['locale'] : 'en';
            $row = $this->cms->menu($args['key'], $scope !== null ? $scope->locale : $locale);
            return $row === null ? null : $this->project($row, $children, 'menu');
        }
        if ($name === 'page') {
            if (!isset($args['slug']) || !is_string($args['slug']) || $args['slug'] === '') throw new HeadlessException('VALIDATION_ERROR', 'Page slug is required.');
            $locale = isset($args['locale']) && is_string($args['locale']) ? $args['locale'] : 'en';
            $row = $this->cms->published($args['slug'], $scope !== null ? $scope->locale : $locale, $scope);
            return $row === null ? null : $this->project($row, $children, 'page');
        }
        if (!isset($args['id']) || !is_string($args['id']) || $args['id'] === '') throw new HeadlessException('VALIDATION_ERROR', 'Order id is required.');
        $id = $args['id'];
        $row = $this->orders->show($id);
        // Do not disclose whether an order exists to an unauthorized caller.
        if ($row === null) throw new HeadlessException('FORBIDDEN', 'Order access is not authorized.');
        $customerId = $row['customer_id'] ?? null;
        $owner = is_array($user) && is_string($customerId) && $this->ownerReader->owns($customerId, $user);
        $token = (string) ($args['token'] ?? '');
        if (!$owner && !$this->orders->guestTokenIsValid($id, $token)) throw new HeadlessException('FORBIDDEN', 'Order access is not authorized.');
        return $this->project($row, $children, 'order');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function project(array $row, array $children, string $type): array
    {
        /** @var array<int, string> $allowed */
        $allowed = HeadlessSchema::definition()[$type] ?? [];
        $out = [];
        foreach ($children as $field) {
            $name = is_string($field['name'] ?? null) ? $field['name'] : '';
            if (!in_array($name, $allowed, true)) throw new HeadlessException('VALIDATION_ERROR', 'Unknown ' . $type . ' field: ' . $name . '.');
            $key = ['digitalAssets' => 'digital_assets', 'createdAt' => 'created_at', 'perPage' => 'per_page', 'lastPage' => 'last_page', 'totals' => 'totals'][$name] ?? $name;
            $value = null;
            if ($name === 'totals') $value = array_intersect_key($row, array_flip(['subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor', 'grand_total_minor']));
            elseif ($name === 'seo') $value = $this->projectNested(normalizeRow($row[$key] ?? null) ?? [], normalizeNodes($field['children'] ?? []), 'seo');
            elseif (isset($row[$key]) && is_array($row[$key])) { $nested = normalizeNodes($field['children'] ?? []); $value = array_map(fn ($item): mixed => normalizeRow($item) !== null ? $this->projectNested(normalizeRow($item), $nested, $name) : $item, $row[$key]); }
            else $value = $row[$key] ?? null;
            $out[is_string($field['alias'] ?? null) ? $field['alias'] : $name] = $value;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function projectNested(array $row, array $children, string $parent): array
    {
        $allowed = [
            'options' => ['id', 'name', 'values'], 'values' => ['id', 'value', 'sort'], 'seo' => ['title', 'description', 'canonical', 'robots', 'schema'],
            'variants' => ['id', 'sku', 'price_minor', 'stock', 'option_value_ids'],
            'components' => ['id', 'product_id', 'component_product_id', 'quantity'],
            'digitalAssets' => ['id', 'variant_id', 'name', 'mime_type', 'size'],
            'blocks' => ['id', 'type', 'content', 'sort'], 'lines' => ['id', 'variant_id', 'product_name', 'qty', 'unit_price_minor'],
        ][$parent] ?? [];
        $out = [];
        foreach ($children as $field) {
            $name = is_string($field['name'] ?? null) ? $field['name'] : '';
            if (!in_array($name, $allowed, true)) throw new HeadlessException('VALIDATION_ERROR', 'Unknown ' . $parent . ' field: ' . $name . '.');
            $key = $name;
            $value = $row[$key] ?? null;
            if ($parent === 'seo' && $name === 'schema') {
                $out[is_string($field['alias'] ?? null) ? $field['alias'] : $name] = $value;
                continue;
            }
            $nested = normalizeNodes($field['children'] ?? []);
            $projected = is_array($value)
                ? array_map(fn ($item): mixed => normalizeRow($item) !== null ? $this->projectNested(normalizeRow($item), $nested, $name) : $item, $value)
                : $value;
            $out[is_string($field['alias'] ?? null) ? $field['alias'] : $name] = $projected;
        }
        return $out;
    }
}

/**
 * @param array<int, array<string, mixed>> $children
 * @return array<int, array<string, mixed>>
 */
function childrenForNodes(array $children): array
{
    foreach ($children as $child) if (($child['name'] ?? '') === 'nodes') return normalizeNodes($child['children'] ?? []);
    return [];
}

/** @return array<int, array<string, mixed>> */
function normalizeNodes(mixed $value): array
{
    if (!is_array($value)) return [];
    $nodes = [];
    foreach ($value as $node) if (($row = normalizeRow($node)) !== null) $nodes[] = $row;
    return $nodes;
}

/** @return array<string, mixed>|null */
function normalizeRow(mixed $value): ?array
{
    if (!is_array($value)) return null;
    $row = [];
    foreach ($value as $key => $item) if (is_string($key)) $row[$key] = $item;
    return $row;
}
