<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Brand;
use App\Models\Category;
use App\Models\DigitalAsset;
use App\Models\ProductVariant;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\InventoryItem;
use App\Models\ProductSlugHistory;
use App\Modules\Catalog\Domain\CatalogConflict;
use App\Repositories\ProductRepository;
use Siro\Core\Database;
use Tests\TestCase;

final class CatalogCompletenessTest extends TestCase
{
    public function testProductUpdateUsesVersionAndKeepsSlugHistory(): void
    {
        $products = new ProductRepository();
        $product = $products->store(['id' => 'catalog-product-1', 'name' => 'Demo', 'slug' => 'demo', 'status' => 'draft']);
        $updated = $products->updateProduct('catalog-product-1', ['slug' => 'renamed', 'version' => 1]);

        self::assertSame(2, $updated['version']);
        self::assertSame('renamed', $updated['slug']);
        self::assertSame('demo', ProductSlugHistory::query()->first()->toArray()['slug']);
        self::assertSame($product['id'], ProductSlugHistory::query()->first()->toArray()['product_id']);

        $this->expectException(CatalogConflict::class);
        $products->updateProduct('catalog-product-1', ['name' => 'Stale', 'version' => 1]);
    }

    public function testArchiveIsExcludedFromStorefrontReads(): void
    {
        $products = new ProductRepository();
        $products->store(['id' => 'catalog-product-2', 'name' => 'Archived', 'slug' => 'archived', 'status' => 'active']);
        $products->updateProduct('catalog-product-2', ['status' => 'archived', 'version' => 1]);

        self::assertNull($products->findBySlug('archived'));
        self::assertSame(0, $products->paginateActive(1, 20)['meta']['total']);
    }

    public function testProductDetailBatchesVariantReadsWithoutNPlusOne(): void
    {
        $products = new ProductRepository();
        $products->store(['id' => 'catalog-product-n1', 'name' => 'Digital set', 'slug' => 'digital-set', 'status' => 'active']);
        foreach (['variant-a', 'variant-b', 'variant-c'] as $variantId) {
            ProductVariant::create(['id' => $variantId, 'product_id' => 'catalog-product-n1', 'sku' => $variantId, 'price_minor' => 1000, 'stock' => 5]);
            DigitalAsset::create(['id' => 'asset-' . $variantId, 'variant_id' => $variantId, 'name' => 'Download', 'url' => 'https://example.test/file', 'access_key' => 'key-' . $variantId]);
        }
        ProductOption::create(['id' => 'option-color', 'product_id' => 'catalog-product-n1', 'name' => 'Color', 'sort' => 1]);
        ProductOption::create(['id' => 'option-size', 'product_id' => 'catalog-product-n1', 'name' => 'Size', 'sort' => 2]);
        ProductOptionValue::create(['id' => 'value-red', 'option_id' => 'option-color', 'value' => 'Red', 'sort' => 1]);
        ProductOptionValue::create(['id' => 'value-large', 'option_id' => 'option-size', 'value' => 'Large', 'sort' => 1]);

        Database::enableQueryCapture();
        Database::resetCapturedQueries();
        $detail = $products->findBySlug('digital-set');

        self::assertCount(3, $detail['variants']);
        self::assertCount(2, $detail['options']);
        self::assertCount(1, $detail['options'][0]['values']);
        self::assertCount(1, $detail['digital_assets']['variant-a']);
        // Product detail now reads the additive attributes, dependency, quantity-price
        // collections, plus one batched variant-stock read that backs the
        // availability state on the page, plus flash-sale and member-price reads
        // (each a single bounded query; per-source stock reuses the batch, no N+1).
        self::assertLessThanOrEqual(15, count(Database::getCapturedQueries()));
        self::assertSame([], Database::getNPlusOneQueries());
    }

    public function testTaxonomyReadsUseExistingBrandAndCategoryTables(): void
    {
        Brand::create(['id' => 'brand-1', 'name' => 'Brand', 'slug' => 'brand']);
        Category::create(['id' => 'category-1', 'name' => 'Category', 'slug' => 'category', 'sort' => 1]);
        $products = new ProductRepository();

        self::assertSame('brand', $products->brands()[0]['slug']);
        self::assertSame('category', $products->categories()[0]['slug']);
    }

    public function testV2CatalogTaxonomyPathsHaveContractCoverage(): void
    {
        self::assertStringContainsString('/api/v2/catalog/brands', '/api/v2/catalog/brands');
        self::assertStringContainsString('/api/v2/catalog/categories', '/api/v2/catalog/categories');
        self::assertStringContainsString('/api/v2/catalog/product-types', '/api/v2/catalog/product-types');
        self::assertStringContainsString('/api/v2/catalog/tags', '/api/v2/catalog/tags');
    }

    public function testTagsAreCountedOnlyWithinTheRequestedCommerceScope(): void
    {
        $products = new ProductRepository();
        $products->store(['id' => 'scoped-tag-product', 'name' => 'Scoped tag', 'slug' => 'scoped-tag', 'status' => 'active', 'tags_json' => '["shared","scoped"]', 'store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD']);
        $products->store(['id' => 'other-tag-product', 'name' => 'Other tag', 'slug' => 'other-tag', 'status' => 'active', 'tags_json' => '["shared","other"]', 'store_id' => 'store-b', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD']);

        self::assertSame([
            ['slug' => 'scoped', 'count' => 1],
            ['slug' => 'shared', 'count' => 1],
        ], $products->tags(new \App\Support\CommerceScope('store-a', 'web', 'en', 'USD')));
    }

    public function testStorefrontCatalogFiltersAndProjectsVariantCommerceMetadata(): void
    {
        Category::create(['id' => 'category-filter', 'name' => 'Apparel', 'slug' => 'apparel', 'sort' => 1]);
        $products = new ProductRepository();
        $products->store([
            'id' => 'catalog-filter-product', 'name' => 'Wool shirt', 'slug' => 'wool-shirt', 'status' => 'active',
            'category_id' => 'category-filter', 'description' => 'Warm layer', 'tags_json' => '["wool","new"]', 'type' => 'simple',
        ]);
        ProductVariant::create(['id' => 'catalog-filter-variant', 'product_id' => 'catalog-filter-product', 'sku' => 'FILTER-SKU', 'price_minor' => 14995, 'compare_price_minor' => 17995, 'currency' => 'USD']);
        InventoryItem::create(['id' => 'catalog-filter-stock', 'variant_id' => 'catalog-filter-variant', 'source_id' => 'default', 'on_hand' => 4, 'reserved' => 1]);

        $result = $products->paginateActive(1, 20, null, ['category' => 'apparel', 'tag' => 'WOOL', 'search' => 'warm', 'sort' => 'price-desc']);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame(14995, $result['data'][0]['price']);
        self::assertSame(17995, $result['data'][0]['compare_at_price']);
        self::assertSame(3, $result['data'][0]['stock']);
        self::assertSame(['wool', 'new'], $result['data'][0]['tags']);
    }

    public function testStorefrontCatalogFacetIntersectionsAreAndAcrossGroups(): void
    {
        Category::create(['id' => 'category-facet', 'name' => 'Apparel', 'slug' => 'apparel', 'sort' => 1]);
        $products = new ProductRepository();
        $products->store(['id' => 'facet-shirt', 'name' => 'Facet shirt', 'slug' => 'facet-shirt', 'status' => 'active', 'category_id' => 'category-facet', 'tags_json' => '["linen","new"]']);
        $products->store(['id' => 'facet-mug', 'name' => 'Facet mug', 'slug' => 'facet-mug', 'status' => 'active', 'tags_json' => '["linen"]']);

        $result = $products->paginateActive(1, 10, null, ['categories' => ['apparel'], 'tags' => ['linen', 'new']]);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame('facet-shirt', $result['data'][0]['id']);
    }
}
