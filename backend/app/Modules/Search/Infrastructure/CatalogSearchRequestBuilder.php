<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use App\Modules\Promotion\Application\MemberPriceService;

/**
 * Builds the Meilisearch request body for the storefront catalog index. Kept
 * separate from the HTTP boundary so filter and sort rules are unit-testable.
 */
final class CatalogSearchRequestBuilder
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(string $q, array $filters, int $limit, int $offset): array
    {
        $body = [
            'q' => $q,
            'limit' => max(0, $limit),
            'offset' => max(0, $offset),
            'attributesToRetrieve' => ['id'],
        ];
        $filter = $this->filter($filters);
        if ($filter !== []) $body['filter'] = $filter;
        $sort = $this->sort($filters);
        if ($sort !== []) $body['sort'] = $sort;
        return $body;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    public function filter(array $filters): array
    {
        $parts = ['status = ' . $this->literal('active'), 'archived = false'];
        foreach (['store_id', 'channel_id', 'locale', 'currency'] as $key) {
            $value = $this->scalar($filters[$key] ?? null);
            if ($value !== null) $parts[] = $key . ' = ' . $this->literal($value);
        }

        $categories = $this->listValues($filters, 'category', 'categories');
        if ($categories !== []) $parts[] = 'category IN [' . implode(', ', array_map(fn (string $v): string => $this->literal($v), $categories)) . ']';

        $brands = $this->listValues($filters, 'brand', 'brands');
        if ($brands !== []) $parts[] = 'brand IN [' . implode(', ', array_map(fn (string $v): string => $this->literal($v), $brands)) . ']';

        $minPrice = $this->number($filters['min_price'] ?? null);
        if ($minPrice !== null) $parts[] = 'price_minor >= ' . (string) $minPrice;
        $maxPrice = $this->number($filters['max_price'] ?? null);
        if ($maxPrice !== null) $parts[] = 'price_minor <= ' . (string) $maxPrice;

        if (($filters['in_stock'] ?? false) === true) $parts[] = 'in_stock = true';

        $viewerTier = is_string($filters['viewer_tier'] ?? null) ? $filters['viewer_tier'] : null;
        $allowed = array_values(array_filter(
            MemberPriceService::TIERS,
            static fn (string $tier): bool => MemberPriceService::rankOf($tier) <= MemberPriceService::rankOf($viewerTier)
        ));
        $parts[] = $allowed === []
            ? 'min_tier IS NULL'
            : '(min_tier IS NULL OR min_tier IN [' . implode(', ', array_map(fn (string $v): string => $this->literal($v), $allowed)) . '])';

        return $parts;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    public function sort(array $filters): array
    {
        $sort = strtolower(trim(is_string($filters['sort'] ?? null) ? $filters['sort'] : ''));
        return match ($sort) {
            'price-asc', 'price_asc' => ['price_minor:asc'],
            'price-desc', 'price_desc' => ['price_minor:desc'],
            'newest', 'created_at-desc' => ['created_at:desc'],
            'oldest', 'created_at-asc' => ['created_at:asc'],
            default => [],
        };
    }

    private function scalar(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') return trim($value);
        if (is_int($value) || is_float($value)) return (string) $value;
        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private function listValues(array $filters, string $singleKey, string $listKey): array
    {
        $values = [];
        $single = $this->scalar($filters[$singleKey] ?? null);
        if ($single !== null) $values[] = $single;
        $list = $filters[$listKey] ?? null;
        if (is_array($list)) {
            foreach ($list as $value) {
                $item = $this->scalar($value);
                if ($item !== null) $values[] = $item;
            }
        }
        $values = array_values(array_unique(array_map('strtolower', $values)));
        sort($values);
        return $values;
    }

    private function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) return $value;
        if (is_string($value) && is_numeric($value)) return $value + 0;
        return null;
    }

    private function literal(string $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
