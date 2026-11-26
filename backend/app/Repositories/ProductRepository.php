<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductSubscriptionPlan;
use App\Models\SubscriptionPlan;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductImage;
use App\Models\ProductComponent;
use App\Models\DigitalAsset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Vendor;
use App\Models\InventoryItem;
use App\Models\ProductSlugHistory;
use App\Modules\Catalog\Domain\CatalogConflict;
use App\Modules\Catalog\Domain\CatalogSearchText;
use App\Modules\Search\Contracts\CatalogSearchProvider;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\Ids;
use App\Support\ImageDerivativeService;
use App\Support\PaginationBounds;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Storage;
use App\Models\VariantOptionValue;
use App\Models\ProductAttribute;
use App\Models\VariantDependency;
use App\Models\VariantQuantityPrice;

/**
 * Stateless data-access only (backend AGENTS §1, §3).
 * Only Model statics — no DB::table outside Models.
 */
final class ProductRepository
{
    use CreatesModels;

    private const FALLBACK_SCAN_LIMIT = 5000;

    public function __construct(private readonly ?CatalogSearchProvider $catalogSearchProvider = null) {}

    /** @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int, facets?: array<string, mixed>}}
     */
    public function paginateActive(int $page, int $perPage, ?CommerceScope $scope = null, array $filters = []): array
    {
        $page = PaginationBounds::page($page);
        $perPage = PaginationBounds::perPage($perPage);
        $search = $this->catalogSearchTerm($filters);
        $sort = Arr::str($filters, 'sort', 'featured');
        $offset = ($page - 1) * $perPage;
        if ($search !== '' && $sort !== '' && $this->providerSupportsSort($sort)) {
            $providerPage = $this->providerCatalogPage($scope, $filters, $search, $page, $perPage, $offset);
            if ($providerPage !== null) return $providerPage;
        }
        $query = $this->catalogFilteredQuery($scope, $filters);
        $total = $query->count();
        if ($total === 0 && $search !== '') {
            $fallback = $this->typoFallbackPage($scope, $filters, $search, $page, $perPage);
            if ($fallback !== null) return $fallback;
        }
        $this->applyCatalogSort($query, $sort, $search);
        $rows = [];
        foreach ($query->limit($perPage)->offset($offset)->get() as $model) $rows[] = Arr::row($model);
        $categories = [];
        $facets = $this->catalogFacets($scope, $filters, $categories);
        return [
            'data' => $this->catalogProjections($rows, $categories),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'facets' => $facets,
            ],
        ];
    }

    /**
     * Provider ids are authoritative for membership and order. The SQL pass only
     * re-applies scope/tier/price/stock guards and hydrates display rows.
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int, facets: array<string, mixed>}}|null
     */
    private function providerCatalogPage(?CommerceScope $scope, array $filters, string $search, int $page, int $perPage, int $offset): ?array
    {
        if ($this->catalogSearchProvider === null) return null;
        $providerFilters = $filters;
        if ($scope !== null) {
            $providerFilters['store_id'] = $scope->storeId;
            $providerFilters['channel_id'] = $scope->channelId;
            $providerFilters['locale'] = $scope->locale;
            $providerFilters['currency'] = $scope->currency;
        }
        try {
            $result = $this->catalogSearchProvider->search($search, $providerFilters, $perPage, $offset);
        } catch (\Throwable) {
            return null;
        }
        if ($result === null) return null;

        $ids = [];
        foreach ($result['ids'] as $id) if ($id !== '') $ids[] = $id;
        $total = max(0, $result['total']);
        $baseFilters = $filters;
        unset($baseFilters['search']);
        if ($ids === []) {
            return [
                'data' => [],
                'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'facets' => $this->emptyCatalogFacets()],
            ];
        }

        $byId = [];
        foreach ($this->catalogFilteredQuery($scope, $baseFilters)->whereIn('products.id', $ids)->get() as $model) {
            $row = Arr::row($model);
            $id = Arr::str($row, 'id');
            if ($id !== '') $byId[$id] = $row;
        }
        $rows = [];
        foreach ($ids as $id) if (isset($byId[$id])) $rows[] = $byId[$id];

        $categories = [];
        $facets = $this->catalogFacets($scope, $baseFilters, $categories, $ids);
        return [
            'data' => $this->catalogProjections($rows, $categories),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'facets' => $facets],
        ];
    }

    private function providerSupportsSort(string $sort): bool
    {
        return in_array($sort, ['featured', 'price-asc', 'price-desc', 'price_asc', 'price_desc'], true);
    }

    /** Merchant view: includes non-published products, but never crosses scope.
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginateAdmin(int $page, int $perPage, CommerceScope $scope, array $filters = []): array
    {
        $page = PaginationBounds::page($page);
        $perPage = PaginationBounds::perPage($perPage);
        $query = Product::query()
            ->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)
            ->where('locale', $scope->locale)->where('currency', $scope->currency);

        $status = strtolower(trim(Arr::str($filters, 'status')));
        if ($status !== '') $query->whereRaw('LOWER(COALESCE(publish_state, status)) = :admin_status', ['admin_status' => $status]);

        $search = strtolower(trim(Arr::str($filters, 'search')));
        if ($search !== '') {
            $pattern = $this->likeContains($search);
            $query->whereRaw("(LOWER(name) LIKE :admin_s_name ESCAPE '~' OR LOWER(slug) LIKE :admin_s_slug ESCAPE '~')", ['admin_s_name' => $pattern, 'admin_s_slug' => $pattern]);
        }

        $category = trim(Arr::str($filters, 'category_id'));
        if ($category !== '') $query->where('category_id', $category);
        if (($filters['in_stock'] ?? false) === true) $query->whereRaw('(allow_backorder = 1 OR ' . $this->catalogStockSql() . ' > 0)');
        if (array_key_exists('min_price', $filters) && is_numeric($filters['min_price'])) $query->whereRaw($this->catalogPriceSql() . ' >= (:admin_min_price + 0)', ['admin_min_price' => (int) $filters['min_price']]);
        if (array_key_exists('max_price', $filters) && is_numeric($filters['max_price'])) $query->whereRaw($this->catalogPriceSql() . ' <= (:admin_max_price + 0)', ['admin_max_price' => (int) $filters['max_price']]);

        $total = $query->count();
        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
        $rows = [];
        foreach ($query->limit($perPage)->offset(($page - 1) * $perPage)->get() as $model) $rows[] = Arr::row($model);
        return ['data' => $this->catalogProjections($rows), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }

    /** @param array<string, mixed> $filters */
    private function catalogFilteredQuery(?CommerceScope $scope, array $filters): \Siro\Core\DB\QueryBuilder
    {
        $query = Product::query()->where('status', 'active')->whereNull('archived_at');
        if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency);

        $viewerRank = \App\Modules\Promotion\Application\MemberPriceService::rankOf(
            array_key_exists('viewer_tier', $filters) && is_string($filters['viewer_tier']) ? $filters['viewer_tier'] : null
        );
        $allowed = array_values(array_filter(\App\Modules\Promotion\Application\MemberPriceService::TIERS, static fn (string $tier): bool => \App\Modules\Promotion\Application\MemberPriceService::rankOf($tier) <= $viewerRank));
        if ($allowed === []) {
            $query->whereNull('min_tier');
        } else {
            $placeholders = [];
            $bindings = [];
            foreach ($allowed as $index => $tier) {
                $key = 'cat_tier_' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $tier;
            }
            $query->whereRaw('(min_tier IS NULL OR min_tier IN (' . implode(', ', $placeholders) . '))', $bindings);
        }

        $categories = [];
        $category = trim(Arr::str($filters, 'category'));
        if ($category !== '') $categories[] = $category;
        if (is_array($filters['categories'] ?? null)) foreach ($filters['categories'] as $value) if (is_string($value) && trim($value) !== '') $categories[] = trim($value);
        $categories = array_values(array_unique(array_map('strtolower', $categories)));
        if ($categories !== []) {
            $placeholders = [];
            $bindings = [];
            foreach ($categories as $index => $value) {
                $key = 'cat_slug_' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $value;
            }
            $query->whereRaw('category_id IN (SELECT id FROM categories WHERE LOWER(slug) IN (' . implode(', ', $placeholders) . '))', $bindings);
        }

        $vendorSlug = strtolower(trim(Arr::str($filters, 'vendor')));
        if ($vendorSlug !== '') {
            $vendorModel = Vendor::query()->where('slug', $vendorSlug)->first();
            $vendorRow = $vendorModel === null ? [] : Arr::row($vendorModel);
            $vendorId = Arr::str($vendorRow, 'id');
            if ($vendorId === '' || Arr::str($vendorRow, 'status') !== 'active') {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('vendor_id', $vendorId);
            }
        }

        $brand = strtolower(trim(Arr::str($filters, 'brand')));
        if ($brand !== '') $query->whereRaw('LOWER(brand_id) = :cat_brand', ['cat_brand' => $brand]);
        $brands = [];
        if (is_array($filters['brands'] ?? null)) foreach ($filters['brands'] as $value) if (is_string($value) && trim($value) !== '') $brands[] = strtolower(trim($value));
        $brands = array_values(array_unique($brands));
        if ($brands !== []) {
            $placeholders = [];
            $bindings = [];
            foreach ($brands as $index => $value) {
                $key = 'cat_brand_' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $value;
            }
            $query->whereRaw('LOWER(brand_id) IN (' . implode(', ', $placeholders) . ')', $bindings);
        }

        $types = [];
        if (is_array($filters['types'] ?? null)) foreach ($filters['types'] as $value) if (is_string($value) && trim($value) !== '') $types[] = trim($value);
        $types = array_values(array_unique($types));
        if ($types !== []) {
            $placeholders = [];
            $bindings = [];
            foreach ($types as $index => $value) {
                $key = 'cat_type_' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $value;
            }
            $query->whereRaw("COALESCE(NULLIF(type, ''), 'simple') IN (" . implode(', ', $placeholders) . ')', $bindings);
        }

        $tag = strtolower(trim(Arr::str($filters, 'tag')));
        if ($tag !== '') $query->whereRaw("LOWER(tags_json) LIKE :cat_tag ESCAPE '~'", ['cat_tag' => $this->likeJsonTag($tag)]);
        $tags = [];
        if (is_array($filters['tags'] ?? null)) foreach ($filters['tags'] as $value) if (is_string($value) && trim($value) !== '') $tags[] = strtolower(trim($value));
        $tags = array_values(array_unique($tags));
        if ($tags !== []) {
            $parts = [];
            $bindings = [];
            foreach ($tags as $index => $value) {
                $key = 'cat_tags_' . $index;
                $parts[] = 'LOWER(tags_json) LIKE :' . $key . " ESCAPE '~'";
                $bindings[$key] = $this->likeJsonTag($value);
            }
            $query->whereRaw('(' . implode(' OR ', $parts) . ')', $bindings);
        }

        if (array_key_exists('min_price', $filters) && is_numeric($filters['min_price'])) $query->whereRaw($this->catalogPriceSql() . ' >= (:cat_min_price + 0)', ['cat_min_price' => (int) $filters['min_price']]);
        if (array_key_exists('max_price', $filters) && is_numeric($filters['max_price'])) $query->whereRaw($this->catalogPriceSql() . ' <= (:cat_max_price + 0)', ['cat_max_price' => (int) $filters['max_price']]);
        if (($filters['in_stock'] ?? false) === true) $query->whereRaw('(allow_backorder = 1 OR ' . $this->catalogStockSql() . ' > 0)');

        $search = $this->catalogSearchTerm($filters);
        if ($search !== '') {
            foreach (CatalogSearchText::tokens($search) as $index => $token) {
                $nameKey = 'cat_s_name_' . $index;
                $descKey = 'cat_s_desc_' . $index;
                $tagsKey = 'cat_s_tags_' . $index;
                $pattern = CatalogSearchText::likeContains($token);
                $query->whereRaw(
                    "(LOWER(COALESCE(name, '')) LIKE :$nameKey ESCAPE '~' OR LOWER(COALESCE(description, '')) LIKE :$descKey ESCAPE '~' OR LOWER(COALESCE(tags_json, '')) LIKE :$tagsKey ESCAPE '~')",
                    [$nameKey => $pattern, $descKey => $pattern, $tagsKey => $pattern]
                );
            }
        }

        return $query;
    }

    /** @param \Siro\Core\DB\QueryBuilder $query */
    private function applyCatalogSort(\Siro\Core\DB\QueryBuilder $query, string $sort, string $search = ''): void
    {
        if ($sort === 'featured') {
            if ($search !== '') {
                $query->orderByRaw($this->searchRelevanceSql($search), 'DESC');
                $query->orderByRaw('LOWER(products.name)', 'ASC');
                $query->orderBy('id', 'asc');
                return;
            }
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
            return;
        }
        $field = str_starts_with($sort, 'price') ? 'price' : 'name';
        $direction = str_ends_with($sort, 'desc') ? 'DESC' : 'ASC';
        if ($field === 'price') {
            $query->orderByRaw($this->catalogPriceSql(), $direction);
        } else {
            $query->orderByRaw('LOWER(products.name)', $direction);
        }
        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    private function catalogPriceSql(): string
    {
        return 'COALESCE((SELECT MIN(pv.price_minor) FROM product_variants pv WHERE pv.product_id = products.id), 0)';
    }

    private function catalogStockSql(): string
    {
        return 'COALESCE((SELECT SUM(CASE WHEN ii.on_hand - ii.reserved > 0 THEN ii.on_hand - ii.reserved ELSE 0 END) FROM inventory_items ii JOIN product_variants pv2 ON pv2.id = ii.variant_id WHERE pv2.product_id = products.id), 0)';
    }

    private function likeContains(string $value): string
    {
        return CatalogSearchText::likeContains($value);
    }

    private function likeJsonTag(string $value): string
    {
        $escaped = str_replace(['~', '%', '_'], ['~~', '~%', '~_'], strtolower($value));
        return '%"' . $escaped . '"%';
    }

    /** @param array<string, mixed> $filters */
    private function catalogSearchTerm(array $filters): string
    {
        $search = CatalogSearchText::normalize(Arr::str($filters, 'search'));
        if ($search === '' || CatalogSearchText::tokens($search) === []) return '';
        return $search;
    }

    private function searchRelevanceSql(string $search): string
    {
        $full = CatalogSearchText::normalize($search);
        $tokens = CatalogSearchText::tokens($search);
        $parts = [];
        if ($full !== '') {
            $parts[] = "CASE WHEN LOWER(COALESCE(products.name, '')) LIKE " . $this->quoteSqlLiteral(CatalogSearchText::likeStartsWith($full)) . " ESCAPE '~' THEN 100 ELSE 0 END";
            $parts[] = "CASE WHEN LOWER(COALESCE(products.name, '')) LIKE " . $this->quoteSqlLiteral(CatalogSearchText::likeContains($full)) . " ESCAPE '~' THEN 60 ELSE 0 END";
        }
        foreach ($tokens as $token) {
            $pattern = $this->quoteSqlLiteral(CatalogSearchText::likeContains($token));
            $parts[] = "CASE WHEN LOWER(COALESCE(products.name, '')) LIKE $pattern ESCAPE '~' THEN 20 ELSE 0 END";
            $parts[] = "CASE WHEN LOWER(COALESCE(products.tags_json, '')) LIKE $pattern ESCAPE '~' THEN 10 ELSE 0 END";
            $parts[] = "CASE WHEN LOWER(COALESCE(products.description, '')) LIKE $pattern ESCAPE '~' THEN 5 ELSE 0 END";
        }
        return $parts === [] ? '0' : '(' . implode(' + ', $parts) . ')';
    }

    private function quoteSqlLiteral(string $value): string
    {
        $quoted = Database::connection()->quote($value);
        return is_string($quoted) ? $quoted : "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int, facets: array<string, mixed>}}|null
     */
    private function typoFallbackPage(?CommerceScope $scope, array $filters, string $search, int $page, int $perPage): ?array
    {
        try {
            $tokens = CatalogSearchText::tokens($search, true);
            if ($tokens === []) return null;
            $full = CatalogSearchText::fold($search);
            $baseFilters = $filters;
            unset($baseFilters['search']);
            $matches = [];
            foreach ($this->catalogFilteredQuery($scope, $baseFilters)->select(['id', 'name'])->orderBy('id')->limit(self::FALLBACK_SCAN_LIMIT)->get() as $model) {
                $row = Arr::row($model);
                $id = Arr::str($row, 'id');
                $name = CatalogSearchText::fold(Arr::str($row, 'name'));
                if ($id === '' || $name === '' || !CatalogSearchText::matchesTokens($name, $tokens)) continue;
                $matches[] = ['id' => $id, 'name' => $name, 'score' => CatalogSearchText::nameScore($full, $tokens, $name)];
            }
            if ($matches === []) return null;
            usort($matches, static function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
                $byName = strcmp((string) $a['name'], (string) $b['name']);
                return $byName !== 0 ? $byName : strcmp((string) $a['id'], (string) $b['id']);
            });
            $total = count($matches);
            $pageIds = array_slice(array_column($matches, 'id'), ($page - 1) * $perPage, $perPage);
            $byId = [];
            if ($pageIds !== []) foreach (Product::query()->whereIn('id', $pageIds)->where('status', 'active')->whereNull('archived_at')->get() as $model) {
                $row = Arr::row($model);
                $id = Arr::str($row, 'id');
                if ($id !== '') $byId[$id] = $row;
            }
            $rows = [];
            foreach ($pageIds as $id) if (isset($byId[$id])) $rows[] = $byId[$id];
            return [
                'data' => $this->catalogProjections($rows),
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'facets' => $this->emptyCatalogFacets(),
                ],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function emptyCatalogFacets(): array
    {
        return ['category' => [], 'brand' => [], 'type' => [], 'tag' => [], 'price' => ['min_minor' => 0, 'max_minor' => 0]];
    }

    /** @param array<string, mixed> $filters
     * @param array<string, array<string, mixed>> $categoryMap
     * @param list<string>|null $restrictIds provider ids for SQL facet counts
     * @return array<string, mixed>
     */
    private function catalogFacets(?CommerceScope $scope, array $filters, array &$categoryMap, ?array $restrictIds = null): array
    {
        $categoryMap = [];
        $categoryCounts = [];
        foreach ($this->facetRows($scope, $filters, 'category_id', "category_id IS NOT NULL AND category_id <> ''", 'category_id', $restrictIds) as $row) {
            $value = Arr::str($row, 'facet_value');
            if ($value !== '') $categoryCounts[$value] = ($categoryCounts[$value] ?? 0) + Arr::int($row, 'facet_count');
        }
        if ($categoryCounts !== []) foreach (Category::query()->whereIn('id', array_keys($categoryCounts))->get() as $model) {
            $categoryRow = Arr::row($model);
            $categoryId = Arr::str($categoryRow, 'id');
            if ($categoryId !== '') $categoryMap[$categoryId] = $categoryRow;
        }
        $bySlug = [];
        foreach ($categoryCounts as $categoryId => $count) {
            $slug = Arr::str($categoryMap[$categoryId] ?? [], 'slug');
            if ($slug !== '') $bySlug[$slug] = ($bySlug[$slug] ?? 0) + $count;
        }
        ksort($bySlug);
        $categoryFacet = [];
        foreach ($bySlug as $value => $count) $categoryFacet[] = ['value' => $value, 'count' => $count];

        $brandCounts = [];
        foreach ($this->facetRows($scope, $filters, 'brand_id', "brand_id IS NOT NULL AND brand_id <> ''", 'brand_id', $restrictIds) as $row) {
            $value = Arr::str($row, 'facet_value');
            if ($value !== '') $brandCounts[$value] = ($brandCounts[$value] ?? 0) + Arr::int($row, 'facet_count');
        }
        ksort($brandCounts);
        $brandFacet = [];
        foreach ($brandCounts as $value => $count) $brandFacet[] = ['value' => $value, 'count' => $count];

        $typeCounts = [];
        foreach ($this->facetRows($scope, $filters, "COALESCE(NULLIF(type, ''), 'simple')", '', 'type', $restrictIds) as $row) {
            $value = Arr::str($row, 'facet_value');
            if ($value !== '') $typeCounts[$value] = ($typeCounts[$value] ?? 0) + Arr::int($row, 'facet_count');
        }
        ksort($typeCounts);
        $typeFacet = [];
        foreach ($typeCounts as $value => $count) $typeFacet[] = ['value' => $value, 'count' => $count];

        $tagFacet = $this->catalogTagFacet($scope, $filters, $restrictIds);

        $priceQuery = $this->catalogFilteredQuery($scope, $filters);
        if ($restrictIds !== null) $priceQuery->whereIn('products.id', $restrictIds);
        $priceRow = $priceQuery
            ->selectRaw('COALESCE(MIN(' . $this->catalogPriceSql() . '), 0) AS min_minor, COALESCE(MAX(' . $this->catalogPriceSql() . '), 0) AS max_minor')
            ->first();
        $price = ['min_minor' => 0, 'max_minor' => 0];
        if ($priceRow !== null) {
            $row = Arr::row($priceRow);
            $price = ['min_minor' => Arr::int($row, 'min_minor'), 'max_minor' => Arr::int($row, 'max_minor')];
        }

        return ['category' => $categoryFacet, 'brand' => $brandFacet, 'type' => $typeFacet, 'tag' => $tagFacet, 'price' => $price];
    }

    /**
     * Tag counts over the whole filtered set, not just the visible page.
     * The lightweight projection reads only id + tags_json (never media or
     * variants) and pages by id so a large catalog never loads in one shot.
     *
     * @param array<string, mixed> $filters
     * @param list<string>|null $restrictIds provider ids, uncached because they change per query
     * @return array<int, array{value: string, count: int}>
     */
    private function catalogTagFacet(?CommerceScope $scope, array $filters, ?array $restrictIds = null): array
    {
        $compute = function () use ($scope, $filters, $restrictIds): array {
            $counts = [];
            $lastId = null;
            do {
                $query = $this->catalogFilteredQuery($scope, $filters)->select(['id', 'tags_json'])->orderBy('id');
                if ($restrictIds !== null) $query->whereIn('products.id', $restrictIds);
                if ($lastId !== null) $query->where('id', '>', $lastId);
                $rows = $query->limit(5000)->get();
                foreach ($rows as $model) {
                    $row = Arr::row($model);
                    $id = Arr::str($row, 'id');
                    if ($id !== '') $lastId = $id;
                    $decoded = json_decode(Arr::str($row, 'tags_json', '[]'), true);
                    if (!is_array($decoded)) continue;
                    foreach ($decoded as $tag) {
                        if (!is_string($tag) || trim($tag) === '') continue;
                        $value = strtolower($tag);
                        $counts[$value] = ($counts[$value] ?? 0) + 1;
                    }
                }
            } while (count($rows) === 5000);
            ksort($counts);
            $result = [];
            foreach ($counts as $value => $count) $result[] = ['value' => $value, 'count' => $count];
            return $result;
        };
        if ($restrictIds !== null) return $this->normalizeTagFacet($compute());
        $scopeKey = $scope === null ? 'global' : $scope->key();
        $key = 'catalog:active:tag_facet:v1:' . $scopeKey . ':' . md5((string) json_encode($this->canonicalFacetFilters($filters)));
        $facet = Cache::remember($key, 30, $compute);
        return $this->normalizeTagFacet($facet);
    }

    /** @return array<int, array{value: string, count: int}> */
    private function normalizeTagFacet(mixed $value): array
    {
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) continue;
            $tag = isset($entry['value']) && is_string($entry['value']) ? $entry['value'] : '';
            if ($tag === '') continue;
            $count = isset($entry['count']) && is_numeric($entry['count']) ? (int) $entry['count'] : 0;
            $result[] = ['value' => $tag, 'count' => $count];
        }
        return $result;
    }

    /**
     * @param array<mixed, mixed> $filters
     * @return array<mixed, mixed>
     */
    private function canonicalFacetFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $key => $value) $out[$key] = is_array($value) ? $this->canonicalFacetFilters($value) : $value;
        if (!array_is_list($out)) ksort($out);
        return $out;
    }

    /** @param array<string, mixed> $filters
     * @param list<string>|null $restrictIds
     * @return array<int, array<string, mixed>> */
    private function facetRows(?CommerceScope $scope, array $filters, string $select, string $where, string $group, ?array $restrictIds = null): array
    {
        $query = $this->catalogFilteredQuery($scope, $filters);
        if ($restrictIds !== null) $query->whereIn('products.id', $restrictIds);
        if ($where !== '') $query->whereRaw($where);
        $query->selectRaw($select . ' AS facet_value, COUNT(*) AS facet_count');
        $query->groupBy($group);
        $rows = [];
        foreach ($query->get() as $model) $rows[] = Arr::row($model);
        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, mixed>> $preloadedCategories
     * @return array<int, array<string, mixed>>
     */
    private function catalogProjections(array $rows, array $preloadedCategories = []): array
    {
        $productIds = [];
        $categoryIds = [];
        $vendorIds = [];
        foreach ($rows as $row) {
            $id = Arr::str($row, 'id');
            if ($id !== '') $productIds[] = $id;
            $categoryId = Arr::str($row, 'category_id');
            if ($categoryId !== '') $categoryIds[] = $categoryId;
            $vendorId = Arr::str($row, 'vendor_id');
            if ($vendorId !== '') $vendorIds[] = $vendorId;
        }
        $productIds = array_values(array_unique($productIds));
        $categoryIds = array_values(array_unique($categoryIds));
        $vendorIds = array_values(array_unique($vendorIds));

        $variantsByProduct = [];
        $variantIds = [];
        if ($productIds !== []) foreach (ProductVariant::query()->whereIn('product_id', $productIds)->orderBy('id')->get() as $model) {
            $variant = Arr::row($model);
            $productId = Arr::str($variant, 'product_id');
            if ($productId === '') continue;
            $variantsByProduct[$productId][] = $variant;
            $variantId = Arr::str($variant, 'id');
            if ($variantId !== '') $variantIds[] = $variantId;
        }

        $stockByVariant = [];
        if ($variantIds !== []) foreach (InventoryItem::query()->whereIn('variant_id', $variantIds)->get() as $model) {
            $item = Arr::row($model);
            $variantId = Arr::str($item, 'variant_id');
            if ($variantId === '') continue;
            $stockByVariant[$variantId] = ($stockByVariant[$variantId] ?? 0) + max(0, Arr::int($item, 'on_hand') - Arr::int($item, 'reserved'));
        }

        $categories = $preloadedCategories;
        $missingCategories = array_values(array_diff($categoryIds, array_keys($categories)));
        if ($missingCategories !== []) foreach (Category::query()->whereIn('id', $missingCategories)->get() as $model) {
            $category = Arr::row($model);
            $categoryId = Arr::str($category, 'id');
            if ($categoryId !== '') $categories[$categoryId] = $category;
        }

        $imagesByProduct = [];
        if ($productIds !== []) foreach (ProductImage::query()->whereIn('product_id', $productIds)->orderBy('sort')->get() as $model) {
            $image = Arr::row($model);
            $productId = Arr::str($image, 'product_id');
            if ($productId !== '') $imagesByProduct[$productId][] = $image;
        }

        $vendors = [];
        if ($vendorIds !== []) foreach (Vendor::query()->whereIn('id', $vendorIds)->get() as $model) {
            $vendor = Arr::row($model);
            $vendorId = Arr::str($vendor, 'id');
            if ($vendorId !== '') $vendors[$vendorId] = ['id' => $vendorId, 'name' => Arr::str($vendor, 'name'), 'slug' => Arr::str($vendor, 'slug')];
        }

        $out = [];
        foreach ($rows as $row) {
            $projected = $this->catalogProjectionFromBatches($row, $variantsByProduct, $stockByVariant, $categories, $imagesByProduct, $vendors);
            if ($projected !== null) $out[] = $projected;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<int, array<string, mixed>>> $variantsByProduct
     * @param array<string, int> $stockByVariant
     * @param array<string, array<string, mixed>> $categories
     * @param array<string, array<int, array<string, mixed>>> $imagesByProduct
     * @param array<string, array{id: string, name: string, slug: string}> $vendors
     * @return array<string, mixed>|null
     */
    private function catalogProjectionFromBatches(array $row, array $variantsByProduct, array $stockByVariant, array $categories, array $imagesByProduct, array $vendors = []): ?array
    {
        $id = Arr::str($row, 'id');
        if ($id === '') return null;
        $variants = $variantsByProduct[$id] ?? [];
        $variant = [];
        foreach ($variants as $candidate) {
            if ($variant === [] || (int) ($candidate['price_minor'] ?? 0) < (int) ($variant['price_minor'] ?? 0)) $variant = $candidate;
        }
        $variant = is_array($variant) ? $variant : [];
        $stock = 0;
        $hasInventory = false;
        foreach ($variants as $candidate) {
            $variantId = Arr::str($candidate, 'id');
            if ($variantId === '' || !array_key_exists($variantId, $stockByVariant)) continue;
            $stock += $stockByVariant[$variantId];
            $hasInventory = true;
        }
        $tags = json_decode(Arr::str($row, 'tags_json', '[]'), true);
        if (!is_array($tags)) $tags = [];
        $tags = array_values(array_filter($tags, static fn (mixed $tag): bool => is_string($tag) && trim($tag) !== ''));
        $category = $categories[Arr::str($row, 'category_id')] ?? [];
        $row['category'] = Arr::str($category, 'slug');
        $row['category_name'] = Arr::str($category, 'name');
        $row['tags'] = $tags;
        $row['description'] = Arr::str($row, 'description');
        $vendorId = Arr::str($row, 'vendor_id');
        $row['vendor_id'] = $vendorId === '' ? null : $vendorId;
        $row['vendor'] = $vendorId !== '' ? ($vendors[$vendorId] ?? null) : null;
        $row['price'] = Arr::int($variant, 'price_minor');
        $row['compare_at_price'] = array_key_exists('compare_price_minor', $variant) ? Arr::int($variant, 'compare_price_minor') : null;
        $row['price_minor'] = $row['price'];
        $row['compare_at_price_minor'] = $row['compare_at_price'];
        $row['money'] = ['amount_minor' => $row['price'], 'currency' => Arr::str($variant, 'currency', Arr::str($row, 'currency', 'VND'))];
        $row['price_evidence'] = ['source' => 'product_variant', 'variant_id' => Arr::str($variant, 'id'), 'amount_minor' => $row['price'], 'compare_at_amount_minor' => $row['compare_at_price'], 'currency' => Arr::str($variant, 'currency', Arr::str($row, 'currency', 'VND'))];
        $row['currency'] = Arr::str($variant, 'currency', Arr::str($row, 'currency', 'VND'));
        $row['stock'] = $hasInventory ? $stock : null;
        $row['allow_backorder'] = (bool) Arr::int($row, 'allow_backorder');
        $row['type'] = Arr::str($row, 'type', 'simple');
        $row['product_type'] = $row['type'];
        $gallery = $imagesByProduct[$id] ?? [];
        $image = $gallery[0] ?? [];
        $row['image'] = Arr::str($image, 'url');
        $row['image_alt'] = Arr::str($image, 'alt', Arr::str($row, 'name'));
        $row['images'] = array_values(array_map(static function (array $item) use ($row): array {
            return self::exposeImageDerivatives([
                'url' => Arr::str($item, 'url'),
                'alt' => Arr::str($item, 'alt', Arr::str($row, 'name')),
                'role' => Arr::str($item, 'role', 'gallery'),
            ], $item);
        }, array_filter($gallery, static fn (array $item): bool => Arr::str($item, 'url') !== '')));
        return $row;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug, ?CommerceScope $scope = null): ?array
    {
        $query = Product::query()->where('slug', $slug)->whereNull('archived_at');
        if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency);
        $row = $query->first();
        if ($row === null) {
            return null;
        }
        $result = Arr::row($row);
        $productId = $result['id'] ?? null;
        if (!is_string($productId)) return $result;
        $result['options'] = $this->options($productId);
        $gallery = $this->images($productId);
        $result['media'] = $gallery;
        // The detail route must expose the same image truth as the list route.
        $primary = $gallery[0] ?? [];
        $result['image'] = Arr::str($primary, 'url');
        $result['image_alt'] = Arr::str($primary, 'alt', Arr::str($result, 'name'));
        $result['images'] = array_values(array_map(static function (array $item) use ($result): array {
            return self::exposeImageDerivatives([
                'url' => Arr::str($item, 'url'),
                'alt' => Arr::str($item, 'alt', Arr::str($result, 'name')),
                'role' => Arr::str($item, 'role', 'gallery'),
            ], $item);
        }, array_filter($gallery, static fn (array $item): bool => Arr::str($item, 'url') !== '')));
        $result['variants'] = $this->variants($productId);
        $result['subscription_plans'] = $this->subscriptionPlansForProduct($productId);
        $result['attributes'] = $this->attributes($productId);
        $result['components'] = array_map(static fn ($component): array => Arr::row($component), ProductComponent::query()->where('product_id', $productId)->get());
        $variantIds = array_values(array_filter(array_map(
            static fn (array $variant): mixed => $variant['id'] ?? null,
            $result['variants'],
        ), static fn (mixed $id): bool => is_string($id) && $id !== ''));
        $assets = array_fill_keys($variantIds, []);
        if ($variantIds !== []) {
            foreach (DigitalAsset::query()->whereIn('variant_id', $variantIds)->get() as $asset) {
                $row = Arr::row($asset);
                $variantId = $row['variant_id'] ?? null;
                if (is_string($variantId) && isset($assets[$variantId])) $assets[$variantId][] = $row;
            }
        }
        $result['digital_assets'] = $assets;
        $result['dependencies'] = $this->dependencies($productId);
        $categoryId = Arr::str($result, 'category_id');
        $category = $categoryId === '' ? null : Category::find($categoryId);
        $result['category'] = $category === null ? '' : Arr::str($category->toArray(), 'slug');
        $result['category_name'] = $category === null ? '' : Arr::str($category->toArray(), 'name');
        $tags = json_decode(Arr::str($result, 'tags_json', '[]'), true);
        $result['tags'] = is_array($tags) ? array_values(array_filter($tags, static fn (mixed $tag): bool => is_string($tag) && trim($tag) !== '')) : [];
        $vendorId = Arr::str($result, 'vendor_id');
        $vendorModel = $vendorId === '' ? null : Vendor::find($vendorId);
        $vendorRow = $vendorModel === null ? [] : Arr::row($vendorModel);
        $result['vendor_id'] = $vendorId === '' ? null : $vendorId;
        $result['vendor'] = $vendorRow === [] ? null : ['id' => Arr::str($vendorRow, 'id'), 'name' => Arr::str($vendorRow, 'name'), 'slug' => Arr::str($vendorRow, 'slug')];
        // A configurable, bundle or grouped parent advertises its entry price:
        // the cheapest purchasable variant, never an arbitrary first row.
        $variant = [];
        foreach ((array) ($result['variants'] ?? []) as $candidate) {
            if ($variant === [] || (int) ($candidate['price_minor'] ?? 0) < (int) ($variant['price_minor'] ?? 0)) $variant = $candidate;
        }
        $variant = is_array($variant) ? $variant : [];
        $result['price'] = Arr::int($variant, 'price_minor');
        $result['compare_at_price'] = array_key_exists('compare_price_minor', $variant) ? Arr::int($variant, 'compare_price_minor') : null;
        $result['currency'] = Arr::str($variant, 'currency', Arr::str($result, 'currency', 'VND'));
        $result['price_minor'] = $result['price']; $result['compare_at_price_minor'] = $result['compare_at_price'];
        $result['money'] = ['amount_minor' => $result['price'], 'currency' => $result['currency']];
        $result['type'] = Arr::str($result, 'type', 'simple'); $result['product_type'] = $result['type'];
        $result['allow_backorder'] = (bool) Arr::int($result, 'allow_backorder');
        // The detail route carries the same availability truth as the list route.
        // One batched inventory read feeds both the status and the variant stock.
        $detailVariantIds = [];
        foreach ((array) ($result['variants'] ?? []) as $detailVariant) {
            if (is_array($detailVariant) && (string) ($detailVariant['id'] ?? '') !== '') $detailVariantIds[] = (string) $detailVariant['id'];
        }
        $detailStockByVariant = [];
        $detailStockBySource = [];
        if ($detailVariantIds !== []) {
            foreach (InventoryItem::query()->whereIn('variant_id', $detailVariantIds)->get() as $item) {
                $stockRow = Arr::row($item);
                $vid = (string) ($stockRow['variant_id'] ?? '');
                $src = Arr::str($stockRow, 'source_id', 'default') ?: 'default';
                $avail = max(0, (int) ($stockRow['on_hand'] ?? 0) - (int) ($stockRow['reserved'] ?? 0));
                // One batched read feeds the page total (summed across sources)
                // and the per-source pickup display.
                $detailStockByVariant[$vid] = ($detailStockByVariant[$vid] ?? 0) + $avail;
                $detailStockBySource[$vid][$src] = $avail;
            }
        }
        $detailStock = $detailVariantIds === [] ? null : array_sum(array_intersect_key($detailStockByVariant, array_flip($detailVariantIds)));
        if ($detailStock !== null) $result['stock'] = $detailStock;
        if (Arr::str($result, 'status') !== 'out_of_stock') {
            $result['status'] = $result['allow_backorder'] && $detailStock === 0 ? 'backorder' : ($detailStock === 0 ? 'out_of_stock' : 'active');
        }
        $currency = Arr::str($result, 'currency', 'VND');
        $detailOptions = is_array($result['options'] ?? null) ? $result['options'] : $this->options($productId);
        $detailVariants = is_array($result['variants'] ?? null) ? $result['variants'] : $this->variants($productId);
        $result['options'] = $this->storefrontOptions($detailOptions);
        $result['variant_options'] = $this->storefrontVariantOptions($detailOptions, $detailVariants, $currency, $detailStockByVariant);
        $result['quantity_pricing'] = $this->storefrontQuantityPricing($detailVariants, $currency);
        $result['option_pricing'] = $this->storefrontOptionPricing($detailOptions, $currency);
        // Set members are only read for set products: a simple product must not
        // pay for component reads it can never use.
        $result['bundle_components'] = $result['type'] === 'bundle' ? $this->storefrontBundleComponents($productId) : [];
        $result['grouped_items'] = $result['type'] === 'grouped' ? $this->storefrontGroupedItems($productId) : [];
        $result['delivery'] = $this->storefrontDelivery($result['type']);
        // Booking slots: expose for type=booking (presentation-only, backend truth is BookingSlot table)
        if ($result['type'] === 'booking') {
            $slots = \App\Models\BookingSlot::query()->where('variant_id', $result['variants'][0]['id'] ?? $productId)->orderBy('starts_at', 'asc')->get();
            // fallback to all variants if primary variant has no slots
            if ($slots === [] && count($result['variants'] ?? []) > 1) {
                $variantIds = array_values(array_filter(array_map(fn($v) => $v['id'] ?? null, $result['variants']), fn($id) => is_string($id) && $id !== ''));
                $slots = \App\Models\BookingSlot::query()->whereIn('variant_id', $variantIds)->orderBy('starts_at', 'asc')->get();
            }
            $result['booking_slots'] = array_map(fn($m) => \App\Support\Arr::row($m), $slots);
            // also aggregate capacity/price for header
            $result['booking'] = ['variant_id' => $result['variants'][0]['id'] ?? $productId, 'slots_count' => count($result['booking_slots']), 'delivery' => $result['delivery']];
        }
        // Member tier prices per variant (native giá member).
        try {
            $memberVariantIds = array_values(array_filter(array_map(fn($v) => is_array($v) ? ($v['id'] ?? null) : null, $result['variants'] ?? []), fn($id) => is_string($id) && $id !== ''));
            if ($memberVariantIds !== []) {
                $member = [];
                foreach (\App\Models\MemberPrice::query()->whereIn('variant_id', $memberVariantIds)->get() as $m) $member[] = \App\Support\Arr::row($m);
                if ($member !== []) $result['member_prices'] = $member;
            }
        } catch (\Throwable $e) {
        }
        // Flash-sale deals active right now, keyed by variant (Hasaki-style giờ vàng).
        try {
            $flashIds = array_values(array_filter(array_map(fn($v) => is_array($v) ? ($v['id'] ?? null) : null, $result['variants'] ?? []), fn($id) => is_string($id) && $id !== ''));
            if ($flashIds !== []) {
                $flash = (new \App\Modules\Promotion\Application\FlashSaleService())->activeFor($flashIds);
                if ($flash !== []) $result['flash_sales'] = array_values($flash);
            }
        } catch (\Throwable $e) {
        }
        // Per-source stock for store pickup display, reused from the single
        // batched inventory read above (no extra query, no N+1).
        if (($detailStockBySource ?? []) !== []) $result['stock_by_source'] = $detailStockBySource;
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function subscriptionPlansForProduct(string $productId): array
    {
        $links = ProductSubscriptionPlan::query()->where('product_id', $productId)->orderBy('sort', 'asc')->orderBy('id', 'asc')->get();
        if ($links === []) return [];
        $planIds = [];
        foreach ($links as $link) {
            $planId = Arr::str(Arr::row($link), 'plan_id');
            if ($planId !== '') $planIds[$planId] = true;
        }
        if ($planIds === []) return [];
        $plans = SubscriptionPlan::query()->whereIn('id', array_keys($planIds))->get();
        $active = [];
        foreach ($plans as $plan) {
            $row = Arr::row($plan);
            if (Arr::str($row, 'state', 'active') === 'active') $active[Arr::str($row, 'id')] = $row;
        }
        $result = [];
        foreach ($links as $link) {
            $planId = Arr::str(Arr::row($link), 'plan_id');
            $plan = $active[$planId] ?? null;
            if ($plan === null) continue;
            $result[] = [
                'id' => Arr::str($plan, 'id'),
                'name' => Arr::str($plan, 'name'),
                'amount_minor' => Arr::int($plan, 'amount_minor'),
                'currency' => strtoupper(Arr::str($plan, 'currency')),
                'interval' => Arr::str($plan, 'interval'),
                'interval_count' => Arr::int($plan, 'interval_count', 1),
                'trial_period' => Arr::nullableStr($plan, 'trial_period'),
                'trial_period_count' => Arr::int($plan, 'trial_period_count'),
            ];
        }
        return $result;
    }

    /** @return array<int, string> */
    public function subscriptionPlanIdsForProduct(string $productId): array
    {
        $ids = [];
        foreach (ProductSubscriptionPlan::query()->where('product_id', $productId)->orderBy('sort', 'asc')->orderBy('id', 'asc')->get() as $link) {
            $planId = Arr::str(Arr::row($link), 'plan_id');
            if ($planId !== '' && !in_array($planId, $ids, true)) $ids[] = $planId;
        }
        return $ids;
    }

    /** @param array<int, mixed> $planIds */
    public function syncSubscriptionPlans(string $productId, array $planIds): void
    {
        $ids = [];
        foreach ($planIds as $planId) {
            if (!is_string($planId)) throw new \InvalidArgumentException('subscription_plan_ids must be a list of plan ids');
            $planId = trim($planId);
            if ($planId !== '' && !in_array($planId, $ids, true)) $ids[] = $planId;
        }
        if ($ids !== []) {
            $found = [];
            foreach (SubscriptionPlan::query()->whereIn('id', $ids)->get() as $plan) {
                $row = Arr::row($plan);
                $found[Arr::str($row, 'id')] = Arr::str($row, 'state', 'active') === 'active';
            }
            foreach ($ids as $planId) {
                if (($found[$planId] ?? false) !== true) throw new \InvalidArgumentException('Subscription plan is unknown or disabled');
            }
        }
        Database::transaction(function () use ($productId, $ids): void {
            ProductSubscriptionPlan::query()->where('product_id', $productId)->delete();
            $now = date('Y-m-d H:i:s');
            foreach ($ids as $sort => $planId) {
                self::createRow(ProductSubscriptionPlan::class, [
                    'id' => Ids::new(),
                    'product_id' => $productId,
                    'variant_id' => null,
                    'plan_id' => $planId,
                    'sort' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
        $product = Product::find($productId);
        if ($product !== null) $this->invalidateCatalogCaches(Arr::str($product->toArray(), 'slug'));
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $row = Product::find($id);
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function findInScope(string $id, CommerceScope $scope): ?array
    {
        $row = Product::query()->where('id', $id)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency)->first();
        return $row === null ? null : Arr::row($row);
    }

    public function optionBelongsToProduct(string $optionId, string $productId): bool
    {
        return ProductOption::query()->where('id', $optionId)->where('product_id', $productId)->first() !== null;
    }

    public function variantInScope(string $variantId, CommerceScope $scope): bool
    {
        $variant = ProductVariant::query()->where('id', $variantId)->first();
        $productId = $variant === null ? '' : Arr::str(Arr::row($variant), 'product_id');
        return $productId !== '' && $this->findInScope($productId, $scope) !== null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(array $data): array
    {
        $data['id'] = Arr::str($data, 'id', Ids::new());
        $data['version'] = Arr::int($data, 'version', 1);
        $result = self::createRow(Product::class, $data);
        $this->invalidateCatalogCaches(Arr::str($result, 'slug'));
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateProduct(string $id, array $data): ?array
    {
        $row = Product::find($id);
        if ($row === null) {
            return null;
        }
        $allowed = ['name', 'slug', 'brand_id', 'status', 'is_preorder', 'description', 'category_id', 'allow_backorder', 'vendor_id'];
        $expected = array_key_exists('version', $data) ? Arr::requiredInt($data, 'version') : Arr::int($row->toArray(), 'version', 1);
        $changes = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('min_tier', $data)) {
            $raw = trim((string) ($data['min_tier'] ?? ''));
            if ($raw !== '' && \App\Modules\Promotion\Application\MemberPriceService::normalizeTier($raw) === null) throw new \InvalidArgumentException('min_tier must be bronze|silver|gold|platinum');
            $changes['min_tier'] = $raw !== '' ? strtolower($raw) : null;
        }
        if (array_key_exists('is_preorder', $data)) {
            $changes['is_preorder'] = filter_var($data['is_preorder'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('allow_backorder', $data)) {
            $changes['allow_backorder'] = filter_var($data['allow_backorder'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $changes['version'] = $expected + 1;
        $status = Arr::str($changes, 'status');
        if ($status === 'archived') {
            $changes['archived_at'] = date('Y-m-d H:i:s');
        } elseif ($status !== '') {
            $changes['archived_at'] = null;
        }
        $updated = Product::query()->where('id', $id)->where('version', $expected)->update($changes);
        if ($updated !== 1) {
            $actual = Product::query()->where('id', $id)->first();
            throw new CatalogConflict($id, $expected, Arr::int($actual?->toArray() ?? [], 'version', 1));
        }
        $oldSlug = Arr::str($row->toArray(), 'slug');
        $newSlug = Arr::str($changes, 'slug', $oldSlug);
        if ($newSlug !== $oldSlug) {
            self::createRow(ProductSlugHistory::class, ['id' => Ids::new(), 'product_id' => $id, 'slug' => $oldSlug]);
        }
        $this->invalidateCatalogCaches($oldSlug);
        $this->invalidateCatalogCaches($newSlug);
        $row = Product::query()->where('id', $id)->first();
        if ($row === null) return null;
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        $vendorId = Arr::str($out, 'vendor_id');
        $vendorModel = $vendorId === '' ? null : Vendor::find($vendorId);
        $vendorRow = $vendorModel === null ? [] : Arr::row($vendorModel);
        $out['vendor_id'] = $vendorId === '' ? null : $vendorId;
        $out['vendor'] = $vendorRow === [] ? null : ['id' => Arr::str($vendorRow, 'id'), 'name' => Arr::str($vendorRow, 'name'), 'slug' => Arr::str($vendorRow, 'slug')];
        return $out;
    }

    public function deleteProduct(string $id): bool
    {
        $row = Product::find($id);
        if ($row === null) {
            return false;
        }
        foreach (ProductImage::query()->where('product_id', $id)->get() as $image) {
            $imageRow = $image->toArray();
            $storagePath = $imageRow['storage_path'] ?? null;
            if (is_string($storagePath) && $storagePath !== '' && Storage::exists($storagePath) && !Storage::delete($storagePath)) {
                throw new \RuntimeException('Unable to remove product media');
            }
            $image->delete();
        }
        $deleted = $row->delete();
        if ($deleted) $this->invalidateCatalogCaches(Arr::str($row->toArray(), 'slug'));
        return $deleted;
    }

    /** @return array<int, array<string, mixed>> */
    public function brands(bool $includeArchived = false): array
    {
        $query = Brand::query();
        if (!$includeArchived) $query->whereNull('archived_at');
        return array_map(static fn ($model): array => $model->toArray(), $query->orderBy('name')->get());
    }

    /** @return array<int, array<string, mixed>> */
    public function categories(bool $includeArchived = false): array
    {
        $query = Category::query();
        if (!$includeArchived) $query->whereNull('archived_at');
        return array_map(static fn ($model): array => $model->toArray(), $query->orderBy('sort')->orderBy('name')->get());
    }

    /** @return array<int, array{slug: string, count: int}> */
    public function tags(?CommerceScope $scope = null): array
    {
        $query = Product::query()->where('status', 'active')->whereNull('archived_at');
        if ($scope !== null) {
            $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->where('locale', $scope->locale)->where('currency', $scope->currency);
        }
        $counts = [];
        foreach ($query->get() as $product) {
            $tags = json_decode(Arr::str(Arr::row($product), 'tags_json', '[]'), true);
            if (!is_array($tags)) continue;
            foreach ($tags as $tag) if (is_string($tag) && trim($tag) !== '') {
                $slug = strtolower(trim($tag)); $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }
        ksort($counts);
        return array_map(static fn (int $count, string $slug): array => ['slug' => $slug, 'count' => $count], $counts, array_keys($counts));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storeVariant(array $data): array
    {
        $data['id'] = Arr::str($data, 'id', Ids::new());
        $result = self::createRow(ProductVariant::class, $data);
        $this->invalidateCatalogCaches();
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storeComponent(array $data): array
    {
        $result = self::createRow(ProductComponent::class, $data + ['id' => Ids::new()]);
        $this->invalidateCatalogCaches();
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storeDigitalAsset(array $data): array
    {
        $result = self::createRow(DigitalAsset::class, $data + ['id' => Ids::new()]);
        $this->invalidateCatalogCaches();
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function components(string $productId): array
    {
        $product = Product::find($productId);
        $productRow = $product?->toArray() ?? [];
        return array_map(static function ($component) use ($productRow): array {
            $row = Arr::row($component);
            $row['bundle_pricing_mode'] = $productRow['bundle_pricing_mode'] ?? 'component_sum';
            $row['bundle_price_minor'] = $productRow['bundle_price_minor'] ?? null;
            $substitutions = $row['substitution_variant_ids'] ?? null;
            if (is_string($substitutions)) $row['substitution_variant_ids'] = json_decode($substitutions, true) ?: [];
            return $row;
        }, ProductComponent::query()->where('product_id', $productId)->get());
    }

    /** @return array<string, mixed>|null */
    public function digitalAsset(string $assetId): ?array
    {
        $asset = DigitalAsset::find($assetId);
        return $asset === null ? null : Arr::row($asset);
    }

    /** @return array<int, array<string, mixed>> */
    public function variants(string $productId): array
    {
        $rows = ProductVariant::query()->where('product_id', $productId)->orderBy('id')->get();
        $variantIds = [];
        $variants = [];
        foreach ($rows as $model) {
            $row = Arr::row($model);
            $id = $row['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $variantIds[] = $id;
                $row['option_value_ids'] = [];
                $row['quantity_prices'] = [];
                $variants[$id] = $row;
            }
        }
        if ($variantIds !== []) {
            foreach (VariantOptionValue::query()->whereIn('variant_id', $variantIds)->get() as $value) {
                $row = Arr::row($value);
                $variantId = $row['variant_id'] ?? null;
                $optionValueId = $row['option_value_id'] ?? null;
                if (is_string($variantId) && isset($variants[$variantId]) && is_string($optionValueId)) {
                    $variants[$variantId]['option_value_ids'][] = $optionValueId;
                }
            }
            foreach (VariantQuantityPrice::query()->whereIn('variant_id', $variantIds)->orderBy('minimum_qty')->get() as $price) {
                $priceRow = Arr::row($price); $variantId = Arr::str($priceRow, 'variant_id');
                if (isset($variants[$variantId])) $variants[$variantId]['quantity_prices'][] = $priceRow;
            }
        }
        return array_values($variants);
    }

    /** @param array<int, string> $optionValueIds */
    public function variantCombinationExists(string $productId, array $optionValueIds): bool
    {
        if ($optionValueIds === []) return false;
        $wanted = array_fill_keys($optionValueIds, true);
        ksort($wanted);
        $variants = ProductVariant::query()->where('product_id', $productId)->get();
        $variantIds = [];
        foreach ($variants as $variant) {
            $id = Arr::str($variant->toArray(), 'id');
            if ($id !== '') $variantIds[] = $id;
        }
        if ($variantIds === []) return false;
        $valuesByVariant = array_fill_keys($variantIds, []);
        foreach (VariantOptionValue::query()->whereIn('variant_id', $variantIds)->get() as $value) {
            $row = Arr::row($value);
            $variantId = $row['variant_id'] ?? null;
            $optionValueId = $row['option_value_id'] ?? null;
            if (is_string($variantId) && isset($valuesByVariant[$variantId]) && is_string($optionValueId)) {
                $valuesByVariant[$variantId][] = $optionValueId;
            }
        }
        foreach ($variantIds as $variantId) {
            $actual = $valuesByVariant[$variantId];
            $actualSet = array_fill_keys($actual, true);
            ksort($actualSet);
            if (count($actual) === count($wanted) && $wanted === $actualSet) return true;
        }
        return false;
    }

   /** Storefront-shaped option, variant, pricing and set data for a product page. Favors rows the detail read already fetched so the page never issues N+1 reads. */
    /** @param array<int, array<string, mixed>> $options
     *  @return array<int, array{name: string, values: array<int, string>}> */
    public function storefrontOptions(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            if (!is_array($option)) continue;
            $values = [];
            foreach ((array) ($option['values'] ?? []) as $value) $values[] = (string) ($value['value'] ?? '');
            $values = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
            if ($values !== []) $out[] = ['name' => (string) ($option['name'] ?? ''), 'values' => $values];
        }
        return $out;
    }

   /** @param array<int, array<string, mixed>> $options
     *  @param array<int, array<string, mixed>> $variants
     *  @param array<string, int>|null $prefetchedStock null means "not provided"
     *  @return array<int, array<string, mixed>> */
    public function storefrontVariantOptions(array $options, array $variants, string $currency, ?array $prefetchedStock = null): array
    {
        $valueNames = [];
        $optionNames = [];
        foreach ($options as $option) {
            if (!is_array($option)) continue;
            $optionNames[(string) ($option['id'] ?? '')] = (string) ($option['name'] ?? '');
            foreach ((array) ($option['values'] ?? []) as $value) {
                if (!is_array($value)) continue;
                $valueNames[(string) ($value['id'] ?? '')] = ['option_id' => (string) ($option['id'] ?? ''), 'value' => (string) ($value['value'] ?? '')];
            }
        }
        $variantIds = [];
        foreach ($variants as $variant) if (is_array($variant) && (string) ($variant['id'] ?? '') !== '') $variantIds[] = (string) $variant['id'];
        $stockByVariant = $prefetchedStock ?? [];
        if ($prefetchedStock === null && $variantIds !== []) {
            foreach (InventoryItem::query()->whereIn('variant_id', $variantIds)->get() as $item) {
                $row = Arr::row($item);
                $stockByVariant[(string) ($row['variant_id'] ?? '')] = (int) ($row['on_hand'] ?? 0) - (int) ($row['reserved'] ?? 0);
            }
        }
        $out = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            $chosen = [];
            foreach ((array) ($variant['option_value_ids'] ?? []) as $valueId) {
                $entry = $valueNames[(string) $valueId] ?? null;
                if (is_array($entry) && ($entry['value'] ?? '') !== '') {
                    $name = $optionNames[$entry['option_id']] ?? '';
                    if ($name !== '') $chosen[$name] = $entry['value'];
                }
            }
            $out[] = [
                'variant_id' => (string) ($variant['id'] ?? ''),
                'sku' => (string) ($variant['sku'] ?? ''),
                'options' => $chosen,
                'price_minor' => (int) ($variant['price_minor'] ?? 0),
                'compare_price_minor' => array_key_exists('compare_price_minor', $variant) ? (int) $variant['compare_price_minor'] : null,
                'currency' => (string) ($variant['currency'] ?? $currency),
                'stock' => array_key_exists((string) ($variant['id'] ?? ''), $stockByVariant) ? $stockByVariant[(string) $variant['id']] : null,
            ];
        }
        return $out;
    }

    /** @param array<int, array<string, mixed>> $variants
     *  @return array<int, array{minimum: int, price: int, currency: string}> */
    public function storefrontQuantityPricing(array $variants, string $currency): array
    {
        $tiers = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            foreach ((array) ($variant['quantity_prices'] ?? []) as $tier) {
                $tiers[] = ['minimum' => (int) ($tier['minimum_qty'] ?? 0), 'price' => (int) ($tier['unit_price_minor'] ?? 0), 'currency' => (string) ($tier['currency'] ?? $currency)];
            }
        }
        usort($tiers, static fn (array $a, array $b): int => $a['minimum'] <=> $b['minimum']);
        return array_values($tiers);
    }

   /** @param array<int, array<string, mixed>> $options
     *  @return array<int, array{option: string, value: string, surcharge: int, currency: string}> */
    public function storefrontOptionPricing(array $options, string $currency): array
    {
        $out = [];
        foreach ($options as $option) {
            if (!is_array($option)) continue;
            foreach ((array) ($option['values'] ?? []) as $value) {
                if (!is_array($value)) continue;
                $surcharge = (int) ($value['surcharge_minor'] ?? 0);
                if ($surcharge > 0) $out[] = ['option' => (string) ($option['name'] ?? ''), 'value' => (string) ($value['value'] ?? ''), 'surcharge' => $surcharge, 'currency' => (string) ($value['currency'] ?? $currency)];
            }
        }
        return $out;
    }

    /**
     * @return array{variants: array<string, array<string, mixed>>, owners: array<string, array<string, mixed>>, images: array<string, array<int, array<string, mixed>>>, stock: array<string, int>}
     */
    private function componentCatalog(array $components): array
    {
        $variantIds = [];
        foreach ($components as $component) {
            $variantId = (string) ($component['component_variant_id'] ?? '');
            if ($variantId !== '') $variantIds[] = $variantId;
        }
        $variantIds = array_values(array_unique($variantIds));
        $variants = [];
        $ownerIds = [];
        if ($variantIds !== []) {
            foreach (ProductVariant::query()->whereIn('id', $variantIds)->get() as $variant) {
                $row = Arr::row($variant);
                $id = (string) ($row['id'] ?? '');
                if ($id === '') continue;
                $variants[$id] = $row;
                if ((string) ($row['product_id'] ?? '') !== '') $ownerIds[] = (string) $row['product_id'];
            }
        }
        $ownerIds = array_values(array_unique($ownerIds));
        $owners = [];
        if ($ownerIds !== []) {
            foreach (Product::query()->whereIn('id', $ownerIds)->get() as $owner) {
                $row = Arr::row($owner);
                $id = (string) ($row['id'] ?? '');
                if ($id !== '') $owners[$id] = $row;
            }
        }
        $images = [];
        if ($ownerIds !== []) {
            foreach (ProductImage::query()->whereIn('product_id', $ownerIds)->orderBy('sort')->get() as $image) {
                $row = Arr::row($image);
                $ownerId = (string) ($row['product_id'] ?? '');
                if ($ownerId !== '') $images[$ownerId][] = $row;
            }
        }
        $stock = [];
        if ($variantIds !== []) {
            foreach (InventoryItem::query()->whereIn('variant_id', $variantIds)->get() as $item) {
                $row = Arr::row($item);
                $stock[(string) ($row['variant_id'] ?? '')] = (int) ($row['on_hand'] ?? 0) - (int) ($row['reserved'] ?? 0);
            }
        }
        return ['variants' => $variants, 'owners' => $owners, 'images' => $images, 'stock' => $stock];
    }

    /** @return array<int, array<string, mixed>> */
    public function storefrontBundleComponents(string $productId): array
    {
        $components = $this->components($productId);
        $catalog = $this->componentCatalog($components);
        $out = [];
        foreach ($components as $component) {
            $variantId = (string) ($component['component_variant_id'] ?? '');
            $variantRow = $catalog['variants'][$variantId] ?? [];
            $ownerRow = $catalog['owners'][(string) ($variantRow['product_id'] ?? '')] ?? [];
            if ($variantId === '' || $ownerRow === []) continue;
            $frames = $catalog['images'][(string) ($ownerRow['id'] ?? '')] ?? [];
            $out[] = [
                'id' => (string) ($component['id'] ?? $variantId),
                'variant_id' => $variantId,
                'name' => Arr::str($ownerRow, 'name', $variantId),
                'slug' => Arr::str($ownerRow, 'slug'),
                'quantity' => max(1, (int) ($component['qty'] ?? 1)),
                'required' => true,
                'price_minor' => (int) ($variantRow['price_minor'] ?? 0),
                'currency' => Arr::str($variantRow, 'currency', 'VND'),
                'image' => Arr::str($frames[0] ?? [], 'url'),
            ];
        }
        return $out;
    }

   /** @return array<int, array<string, mixed>> grouped children sold separately */
    public function storefrontGroupedItems(string $productId): array
    {
        $components = $this->components($productId);
        $catalog = $this->componentCatalog($components);
        $out = [];
        foreach ($components as $component) {
            $variantId = (string) ($component['component_variant_id'] ?? '');
            $variantRow = $catalog['variants'][$variantId] ?? [];
            $ownerRow = $catalog['owners'][(string) ($variantRow['product_id'] ?? '')] ?? [];
            if ($variantId === '' || $ownerRow === []) continue;
            $frames = $catalog['images'][(string) ($ownerRow['id'] ?? '')] ?? [];
            $out[] = [
                'product_id' => (string) ($ownerRow['id'] ?? ''),
                'variant_id' => $variantId,
                'name' => Arr::str($ownerRow, 'name', $variantId),
                'slug' => Arr::str($ownerRow, 'slug'),
                'price_minor' => (int) ($variantRow['price_minor'] ?? 0),
                'currency' => Arr::str($variantRow, 'currency', 'VND'),
                'image' => Arr::str($frames[0] ?? [], 'url'),
                'stock' => $catalog['stock'][$variantId] ?? null,
                'backorderable' => (bool) Arr::int($ownerRow, 'allow_backorder'),
            ];
        }
        return $out;
    }

    /** @return array{mode: string, state: string} */
    public function storefrontDelivery(string $type): array
    {
        return match ($type) {
            'downloadable' => ['mode' => 'downloadable', 'state' => 'available'],
            'virtual' => ['mode' => 'virtual', 'state' => 'available'],
            default => ['mode' => 'physical', 'state' => 'available'],
        };
    }

    /** @return array<int, string> */
    public function optionValuesBelongToProduct(string $productId, array $optionValueIds): bool
    {
        if ($optionValueIds === []) return true;
        $options = ProductOption::query()->where('product_id', $productId)->get();
        $optionIds = [];
        foreach ($options as $option) {
            $row = $option->toArray();
            if (is_string($row['id'] ?? null)) $optionIds[$row['id']] = true;
        }
        $found = [];
        foreach (ProductOptionValue::query()->get() as $value) {
            $row = $value->toArray();
            if (!is_string($row['id'] ?? null) || !is_string($row['option_id'] ?? null)) continue;
            if (isset($optionIds[$row['option_id']]) && in_array($row['id'], $optionValueIds, true)) $found[$row['id']] = true;
        }
        return count($found) === count($optionValueIds);
    }

    public function storeVariantOption(string $variantId, string $optionId, string $optionValueId): void
    {
        self::createRow(VariantOptionValue::class, ['variant_id' => $variantId, 'option_id' => $optionId, 'option_value_id' => $optionValueId]);
        $this->invalidateCatalogCaches();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storeOption(array $data): array
    {
        /** @var array<string, mixed> $row */
        $row = self::createRow(ProductOption::class, $data);
        $this->invalidateCatalogCaches();
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storeOptionValue(array $data): array
    {
        /** @var array<string, mixed> $row */
        $row = self::createRow(ProductOptionValue::class, $data);
        $this->invalidateCatalogCaches();
        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function options(string $productId): array
    {
        $options = ProductOption::query()->where('product_id', $productId)->orderBy('sort')->orderBy('name')->orderBy('id')->get();
        $optionIds = [];
        $result = [];
        foreach ($options as $option) {
            $row = Arr::row($option);
            $id = Arr::str($row, 'id');
            if ($id === '') continue;
            $optionIds[] = $id;
            $row['values'] = [];
            $result[$id] = $row;
        }
        if ($optionIds === []) return [];
        foreach (ProductOptionValue::query()->whereIn('option_id', $optionIds)->orderBy('sort')->orderBy('value')->orderBy('id')->get() as $value) {
            $row = Arr::row($value);
            $optionId = Arr::str($row, 'option_id');
            if (isset($result[$optionId])) $result[$optionId]['values'][] = $row;
        }
        return array_values($result);
    }

    /** @return array<int, array<string, mixed>> */
    public function attributes(string $productId): array
    {
        return array_map(static fn ($row): array => Arr::row($row), ProductAttribute::query()->where('product_id', $productId)->orderBy('code')->get());
    }

    /** @return array<int, array<string, mixed>> */
    public function dependencies(string $productId): array
    {
        return array_map(static fn ($row): array => Arr::row($row), VariantDependency::query()->where('product_id', $productId)->get());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function storeImage(array $data): array
    {
        $data['id'] = Arr::str($data, 'id', Ids::new());
        /** @var array<string, mixed> $row */
        $row = self::createRow(ProductImage::class, $data);
        $this->invalidateCatalogCaches();
        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function images(string $productId): array
    {
        return array_map(static function ($image): array {
            $row = Arr::row($image);
            return self::exposeImageDerivatives(array_intersect_key($row, array_flip(['id', 'product_id', 'url', 'alt', 'sort', 'type', 'mime_type', 'width', 'height', 'size_bytes', 'checksum', 'title', 'role', 'created_at', 'updated_at'])), $row);
        }, ProductImage::query()->where('product_id', $productId)->orderBy('sort')->get());
    }

    /** @return array<string, mixed>|null */
    public function deleteImage(string $productId, string $imageId): ?array
    {
        $image = ProductImage::query()->where('id', $imageId)->where('product_id', $productId)->first();
        if ($image === null) return null;
        $row = Arr::row($image);
        $storagePath = $row['storage_path'] ?? null;
        if (is_string($storagePath) && $storagePath !== '' && Storage::exists($storagePath) && !Storage::delete($storagePath)) {
            throw new \RuntimeException('Unable to remove product media');
        }
        if (!$image->delete()) return null;
        $this->invalidateCatalogCaches();
        return self::exposeImageDerivatives(array_intersect_key($row, array_flip(['id', 'product_id', 'url', 'alt', 'sort', 'type', 'mime_type', 'width', 'height', 'size_bytes', 'checksum', 'title', 'role', 'created_at', 'updated_at'])), $row);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function exposeImageDerivatives(array $result, array $source): array
    {
        $url = $result['url'] ?? null;
        if (is_string($url)) {
            $result['url'] = ImageDerivativeService::publicUrl($url);
        }
        $variants = [];
        $raw = $source['variants_json'] ?? $source['variants'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (is_array($raw)) {
            foreach ($raw as $variant) {
                if (!is_array($variant)) continue;
                $width = $variant['w'] ?? null;
                $url = $variant['url'] ?? null;
                $format = $variant['format'] ?? 'webp';
                if (!is_int($width) || $width < 1 || !is_string($url) || trim($url) === '' || !is_string($format)) continue;
                $variants[] = ['w' => $width, 'url' => ImageDerivativeService::publicUrl($url), 'format' => $format];
            }
        }
        if ($variants !== []) $result['variants'] = $variants;

        $placeholder = $source['placeholder'] ?? null;
        if (is_string($placeholder) && trim($placeholder) !== '') $result['placeholder'] = $placeholder;

        return $result;
    }

    /** @param array<int, string> $mediaIds */
    public function reorderImages(string $productId, array $mediaIds): void
    {
        $rows = ProductImage::query()->where('product_id', $productId)->get();
        $known = [];
        foreach ($rows as $row) {
            $id = Arr::str($row->toArray(), 'id');
            if ($id !== '') $known[$id] = true;
        }
        if (count($mediaIds) !== count($known) || count(array_unique($mediaIds)) !== count($mediaIds) || array_diff($mediaIds, array_keys($known)) !== []) {
            throw new \InvalidArgumentException('Media order must contain every product media id exactly once');
        }
        foreach ($mediaIds as $sort => $mediaId) {
            ProductImage::query()->where('id', $mediaId)->where('product_id', $productId)->update(['sort' => $sort]);
        }
        $this->invalidateCatalogCaches();
    }

    /**
     * Bounded admin CSV export projection. Reads the native catalog in pages
     * of one query each (products, variants, inventory, categories) so a
     * 10k-row export never becomes an N+1 or loads unrelated media.
     *
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool, total: int}
     */
    public function exportAdminCsvRows(CommerceScope $scope, int $limit = 10000): array
    {
        $limit = max(1, min($limit, 10000));
        $query = Product::query()
            ->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)
            ->where('locale', $scope->locale)->where('currency', $scope->currency);
        $total = $query->count();
        $products = [];
        foreach ($query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($limit + 1)->get() as $model) {
            $products[] = Arr::row($model);
        }
        $truncated = count($products) > $limit;
        if ($truncated) $products = array_slice($products, 0, $limit);

        $productIds = [];
        $categoryIds = [];
        foreach ($products as $product) {
            $id = Arr::str($product, 'id');
            if ($id !== '') $productIds[] = $id;
            $categoryId = Arr::str($product, 'category_id');
            if ($categoryId !== '') $categoryIds[] = $categoryId;
        }

        $variantByProduct = [];
        $variantIds = [];
        if ($productIds !== []) {
            foreach (ProductVariant::query()->whereIn('product_id', $productIds)->orderBy('price_minor')->orderBy('id')->get() as $model) {
                $variant = Arr::row($model);
                $productId = Arr::str($variant, 'product_id');
                if ($productId === '' || isset($variantByProduct[$productId])) continue;
                $variantByProduct[$productId] = $variant;
                $variantId = Arr::str($variant, 'id');
                if ($variantId !== '') $variantIds[] = $variantId;
            }
        }

        $stockByVariant = [];
        if ($variantIds !== []) {
            foreach (InventoryItem::query()->whereIn('variant_id', $variantIds)->get() as $model) {
                $item = Arr::row($model);
                $variantId = Arr::str($item, 'variant_id');
                if ($variantId === '') continue;
                $stockByVariant[$variantId] = ($stockByVariant[$variantId] ?? 0) + max(0, Arr::int($item, 'on_hand') - Arr::int($item, 'reserved'));
            }
        }

        $categorySlugs = [];
        if ($categoryIds !== []) {
            foreach (Category::query()->whereIn('id', array_values(array_unique($categoryIds)))->get() as $model) {
                $category = Arr::row($model);
                $id = Arr::str($category, 'id');
                if ($id !== '') $categorySlugs[$id] = Arr::str($category, 'slug');
            }
        }

        $rows = [];
        foreach ($products as $product) {
            $productId = Arr::str($product, 'id');
            $variant = $variantByProduct[$productId] ?? [];
            $variantId = Arr::str($variant, 'id');
            $compare = $variant['compare_price_minor'] ?? null;
            $rows[] = [
                'sku' => Arr::str($variant, 'sku'),
                'name' => Arr::str($product, 'name'),
                'slug' => Arr::str($product, 'slug'),
                'status' => Arr::str($product, 'status'),
                'price_minor' => Arr::int($variant, 'price_minor'),
                'compare_price_minor' => is_numeric($compare) ? (int) $compare : '',
                'stock' => $variantId === '' ? 0 : ($stockByVariant[$variantId] ?? 0),
                'description' => Arr::str($product, 'description'),
                'category' => $categorySlugs[Arr::str($product, 'category_id')] ?? '',
                'min_tier' => Arr::str($product, 'min_tier'),
                'allow_backorder' => Arr::int($product, 'allow_backorder') ? 1 : 0,
                'is_preorder' => Arr::int($product, 'is_preorder') ? 1 : 0,
            ];
        }
        return ['rows' => $rows, 'truncated' => $truncated, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findVariantBySku(string $sku): ?array
    {
        $model = ProductVariant::query()->where('sku', $sku)->first();
        return $model === null ? null : Arr::row($model);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateVariant(string $id, array $data): ?array
    {
        $allowed = ['price_minor', 'compare_price_minor', 'currency', 'status', 'tax_class', 'weight_g', 'length_mm', 'width_mm', 'height_mm'];
        $changes = array_intersect_key($data, array_flip($allowed));
        if ($changes === []) return $this->variantRow($id);
        ProductVariant::query()->where('id', $id)->update($changes);
        $this->invalidateCatalogCaches();
        return $this->variantRow($id);
    }

    public function variantOnHand(string $variantId, string $sourceId = 'default'): int
    {
        $model = InventoryItem::query()->where('variant_id', $variantId)->where('source_id', $sourceId)->first();
        if ($model === null) return 0;
        $row = Arr::row($model);
        return Arr::int($row, 'on_hand');
    }

    public function categoryIdBySlug(string $slug): ?string
    {
        $trimmed = strtolower(trim($slug));
        if ($trimmed === '') return null;
        $model = Category::query()->whereRaw('LOWER(slug) = :category_slug', ['category_slug' => $trimmed])->first();
        if ($model === null) return null;
        $id = Arr::str(Arr::row($model), 'id');
        return $id === '' ? null : $id;
    }

    public function slugTakenInScope(string $slug, CommerceScope $scope, string $exceptProductId = ''): bool
    {
        $query = Product::query()
            ->where('slug', $slug)
            ->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)
            ->where('locale', $scope->locale)->where('currency', $scope->currency);
        if ($exceptProductId !== '') $query->where('id', '!=', $exceptProductId);
        return $query->first() !== null;
    }

    /** @return array<string, mixed>|null */
    private function variantRow(string $id): ?array
    {
        $model = ProductVariant::query()->where('id', $id)->first();
        return $model === null ? null : Arr::row($model);
    }

    private function invalidateCatalogCaches(string $slug = ''): void
    {
        if ($slug !== '') Cache::forget('catalog:slug:' . $slug);
        Cache::flush('catalog:active:');
        Cache::flush('catalog:slug:');
    }
}
