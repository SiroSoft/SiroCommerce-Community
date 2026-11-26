<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Modules\Catalog\Application\CatalogService;
use App\Modules\Platform\Infrastructure\ProductCatalogReader;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

final class CatalogQueryPushdownTest extends TestCase
{
    private function product(string $id, string $name, array $extra = []): void
    {
        (new ProductRepository())->store(array_merge([
            'id' => $id,
            'name' => $name,
            'slug' => $id,
            'status' => 'active',
            'store_id' => 'default',
            'channel_id' => 'default',
            'locale' => 'en',
            'currency' => 'VND',
        ], $extra));
    }

    private function variant(string $id, string $productId, int $price, ?int $compare = null): void
    {
        ProductVariant::create([
            'id' => $id,
            'product_id' => $productId,
            'sku' => strtoupper($id),
            'price_minor' => $price,
            'compare_price_minor' => $compare,
            'currency' => 'VND',
        ]);
    }

    private function stock(string $id, string $variantId, int $onHand, int $reserved = 0, string $source = 'default'): void
    {
        InventoryItem::create([
            'id' => $id,
            'variant_id' => $variantId,
            'source_id' => $source,
            'on_hand' => $onHand,
            'reserved' => $reserved,
        ]);
    }

    public function testPaginationReturnsExactTotalsAndOnlyPageRows(): void
    {
        foreach (range(1, 5) as $index) {
            $this->product('page-product-' . $index, 'Page product ' . $index);
        }

        $products = new ProductRepository();
        $first = $products->paginateActive(1, 2);
        $second = $products->paginateActive(2, 2);
        $third = $products->paginateActive(3, 2);

        self::assertSame(5, $first['meta']['total']);
        self::assertSame(3, $first['meta']['last_page']);
        self::assertCount(2, $first['data']);
        self::assertCount(2, $second['data']);
        self::assertCount(1, $third['data']);
        $ids = array_merge(
            array_column($first['data'], 'id'),
            array_column($second['data'], 'id'),
            array_column($third['data'], 'id'),
        );
        self::assertCount(5, array_unique($ids));
    }

    public function testCatalogPageUsesBoundedQueriesWithoutNPlusOne(): void
    {
        Category::create(['id' => 'q-cat', 'name' => 'Query', 'slug' => 'query', 'sort' => 1]);
        $this->product('q-product', 'Query product', ['category_id' => 'q-cat']);
        $this->variant('q-variant', 'q-product', 1000);
        $this->stock('q-stock', 'q-variant', 3);
        ProductImage::create(['id' => 'q-image', 'product_id' => 'q-product', 'url' => 'https://example.test/q.png', 'alt' => 'Q', 'sort' => 0, 'role' => 'gallery']);

        Database::enableQueryCapture();
        Database::resetCapturedQueries();
        $result = (new ProductRepository())->paginateActive(1, 10);

        self::assertCount(1, $result['data']);
        self::assertLessThanOrEqual(12, count(Database::getCapturedQueries()));
        self::assertSame([], Database::getNPlusOneQueries());
    }

    public function testPriceSortAppliesToWholeCatalogNotJustPage(): void
    {
        $prices = ['sort-product-a' => 500, 'sort-product-b' => 100, 'sort-product-c' => 300, 'sort-product-d' => 200, 'sort-product-e' => 400];
        foreach ($prices as $id => $price) {
            $this->product($id, 'Sort ' . $id);
            $this->variant($id . '-v', $id, $price);
        }

        $products = new ProductRepository();
        $ascending = [];
        foreach ([1, 2, 3] as $page) {
            foreach ($products->paginateActive($page, 2, null, ['sort' => 'price-asc'])['data'] as $row) $ascending[] = $row['price'];
        }
        self::assertSame([100, 200, 300, 400, 500], $ascending);

        $descending = [];
        foreach ([1, 2, 3] as $page) {
            foreach ($products->paginateActive($page, 2, null, ['sort' => 'price-desc'])['data'] as $row) $descending[] = $row['price'];
        }
        self::assertSame([500, 400, 300, 200, 100], $descending);
    }

    public function testCategoryTagPriceStockAndSearchFiltersRunInSql(): void
    {
        Category::create(['id' => 'filter-cat', 'name' => 'Apparel', 'slug' => 'apparel', 'sort' => 1]);
        $this->product('filter-shirt', 'Wool shirt', ['category_id' => 'filter-cat', 'description' => 'Warm layer', 'tags_json' => '["wool","new"]']);
        $this->variant('filter-shirt-v', 'filter-shirt', 1000);
        $this->stock('filter-shirt-stock', 'filter-shirt-v', 3, 1);
        $this->product('filter-mug', 'Steel mug', ['description' => 'Cold drink', 'tags_json' => '["linen"]']);
        $this->variant('filter-mug-v', 'filter-mug', 5000);
        $this->product('filter-backorder', 'Backorder item', ['tags_json' => '["wool"]', 'allow_backorder' => 1]);

        $products = new ProductRepository();

        self::assertSame(['filter-shirt'], array_column($products->paginateActive(1, 20, null, ['category' => 'apparel'])['data'], 'id'));
        $tagIds = array_column($products->paginateActive(1, 20, null, ['tag' => 'WOOL'])['data'], 'id');
        sort($tagIds);
        self::assertSame(['filter-backorder', 'filter-shirt'], $tagIds);
        self::assertSame(['filter-shirt'], array_column($products->paginateActive(1, 20, null, ['min_price' => 500, 'max_price' => 2000])['data'], 'id'));
        $stockIds = array_column($products->paginateActive(1, 20, null, ['in_stock' => true])['data'], 'id');
        sort($stockIds);
        self::assertSame(['filter-backorder', 'filter-shirt'], $stockIds);
        self::assertSame(['filter-shirt'], array_column($products->paginateActive(1, 20, null, ['search' => 'warm'])['data'], 'id'));

        $combined = $products->paginateActive(1, 20, null, ['category' => 'apparel', 'tag' => 'wool', 'search' => 'warm', 'sort' => 'price-asc']);
        self::assertSame(1, $combined['meta']['total']);
        self::assertSame('filter-shirt', $combined['data'][0]['id']);
    }

    public function testScopeAndTierGatingRemainInForce(): void
    {
        $scope = new CommerceScope('store-a', 'web', 'en', 'USD');
        $this->product('scope-public', 'Public product', ['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD']);
        $this->product('scope-gold', 'Gold product', ['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD', 'min_tier' => 'gold']);
        $this->product('scope-other', 'Other store product', ['store_id' => 'store-b', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD']);

        $products = new ProductRepository();
        self::assertSame(['scope-public'], array_column($products->paginateActive(1, 20, $scope)['data'], 'id'));
        $goldIds = array_column($products->paginateActive(1, 20, $scope, ['viewer_tier' => 'gold'])['data'], 'id');
        sort($goldIds);
        self::assertSame(['scope-gold', 'scope-public'], $goldIds);
        self::assertSame(['scope-public'], array_column($products->paginateActive(1, 20, $scope, ['viewer_tier' => 'silver'])['data'], 'id'));
        self::assertSame(['scope-public'], array_column($products->paginateActive(1, 20, $scope, ['viewer_tier' => 'bronze'])['data'], 'id'));
    }

    public function testCatalogCacheKeyVariesWithFilters(): void
    {
        $this->product('cache-alpha', 'Alpha serum');
        $this->product('cache-beta', 'Beta serum');
        $service = new CatalogService(new ProductCatalogReader(new ProductRepository()));

        $alpha = $service->listActive(1, 10, null, ['search' => 'alpha', 'sort' => 'featured']);
        $beta = $service->listActive(1, 10, null, ['search' => 'beta', 'sort' => 'featured']);

        self::assertSame('cache-alpha', $alpha['data'][0]['id'] ?? null);
        self::assertSame('cache-beta', $beta['data'][0]['id'] ?? null);
    }

    public function testListRowsCarryVariantDerivedPriceStockAndImages(): void
    {
        Category::create(['id' => 'shape-cat', 'name' => 'Shape', 'slug' => 'shape', 'sort' => 1]);
        $this->product('shape-product', 'Shape product', ['category_id' => 'shape-cat', 'tags_json' => '["shape"]']);
        $this->variant('shape-v1', 'shape-product', 14995, 17995);
        $this->variant('shape-v2', 'shape-product', 12995, 15995);
        $this->stock('shape-stock-1', 'shape-v1', 4, 1);
        $this->stock('shape-stock-2', 'shape-v2', 6, 2, 'store-hcm');

        ProductImage::create(['id' => 'shape-image-1', 'product_id' => 'shape-product', 'url' => 'https://example.test/1.png', 'alt' => 'First', 'sort' => 0, 'role' => 'gallery']);
        ProductImage::create(['id' => 'shape-image-2', 'product_id' => 'shape-product', 'url' => 'https://example.test/2.png', 'alt' => 'Second', 'sort' => 1, 'role' => 'gallery']);

        $result = (new ProductRepository())->paginateActive(1, 10);
        self::assertCount(1, $result['data']);
        $row = $result['data'][0];

        self::assertSame(12995, $row['price']);
        self::assertSame(15995, $row['compare_at_price']);
        self::assertSame(7, $row['stock']);
        self::assertSame('shape', $row['category']);
        self::assertSame('Shape', $row['category_name']);
        self::assertSame(['shape'], $row['tags']);
        self::assertSame('https://example.test/1.png', $row['image']);
        self::assertSame(['https://example.test/1.png', 'https://example.test/2.png'], array_column($row['images'], 'url'));
        self::assertSame(12995, $result['meta']['facets']['price']['min_minor']);
        self::assertSame(12995, $result['meta']['facets']['price']['max_minor']);
        self::assertSame([['value' => 'shape', 'count' => 1]], $result['meta']['facets']['category']);
    }

    public function testAdminCatalogFiltersAndBatchProjectsRows(): void
    {
        $scope = new CommerceScope('default', 'default', 'en', 'VND');
        $this->product('admin-active', 'Admin active', ['publish_state' => 'active']);
        $this->variant('admin-active-v', 'admin-active', 2500);
        $this->stock('admin-active-stock', 'admin-active-v', 8);
        $this->product('admin-draft', 'Admin draft', ['publish_state' => 'draft']);
        $this->variant('admin-draft-v', 'admin-draft', 9000);

        ProductImage::create(['id' => 'admin-image', 'product_id' => 'admin-active', 'url' => 'https://example.test/admin.png', 'alt' => 'Admin', 'sort' => 0, 'role' => 'gallery']);

        $products = new ProductRepository();
        $active = $products->paginateAdmin(1, 20, $scope, ['status' => 'active']);
        self::assertSame(['admin-active'], array_column($active['data'], 'id'));
        self::assertSame(2500, $active['data'][0]['price']);
        self::assertSame(8, $active['data'][0]['stock']);
        self::assertSame('https://example.test/admin.png', $active['data'][0]['image']);

        self::assertSame(['admin-active'], array_column($products->paginateAdmin(1, 20, $scope, ['in_stock' => true])['data'], 'id'));
        self::assertSame(['admin-active'], array_column($products->paginateAdmin(1, 20, $scope, ['max_price' => 3000])['data'], 'id'));
        self::assertSame(['admin-draft'], array_column($products->paginateAdmin(1, 20, $scope, ['search' => 'draft'])['data'], 'id'));
    }
}
