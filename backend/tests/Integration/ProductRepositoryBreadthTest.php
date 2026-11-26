<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Catalog\Domain\CatalogConflict;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Siro\Core\Storage;
use Tests\TestCase;

final class ProductRepositoryBreadthTest extends TestCase
{
    private ProductRepository $products;

    private CommerceScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->products = new ProductRepository();
        $this->scope = new CommerceScope('default', 'web', 'en', 'USD');
    }

    protected function tearDown(): void
    {
        Storage::reset();
        parent::tearDown();
    }

    public function testProductCrudScopeConflictFacetsAndExport(): void
    {
        Database::table('brands')->insert(['id' => 'brand-1', 'name' => 'Acme', 'slug' => 'acme']);
        Database::table('categories')->insert(['id' => 'cat-1', 'name' => 'Lighting', 'slug' => 'lighting', 'sort' => 1]);
        Database::table('categories')->insert(['id' => 'cat-old', 'name' => 'Old', 'slug' => 'old', 'sort' => 2, 'archived_at' => '2026-01-01 00:00:00']);

        $created = $this->products->store([
            'id' => 'breadth-product-1', 'name' => 'Breadth Lamp', 'slug' => 'breadth-lamp',
            'status' => 'active', 'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en',
            'currency' => 'USD', 'category_id' => 'cat-1', 'brand_id' => 'brand-1', 'description' => 'Lamp',
            'tags_json' => '["Sale","New"]', 'allow_backorder' => 0, 'type' => 'simple',
        ]);
        self::assertSame('breadth-product-1', $created['id']);
        self::assertNotNull($this->products->find('breadth-product-1'));
        self::assertNull($this->products->find('missing-product'));
        self::assertNotNull($this->products->findInScope('breadth-product-1', $this->scope));
        self::assertNull($this->products->findInScope('breadth-product-1', new CommerceScope('other', 'web', 'en', 'USD')));
        self::assertFalse($this->products->optionBelongsToProduct('option-x', 'breadth-product-1'));
        self::assertFalse($this->products->variantInScope('variant-x', $this->scope));
        self::assertTrue($this->products->slugTakenInScope('breadth-lamp', $this->scope));
        self::assertFalse($this->products->slugTakenInScope('breadth-lamp', $this->scope, 'breadth-product-1'));

        $this->products->storeVariant([
            'id' => 'breadth-variant-1', 'product_id' => 'breadth-product-1', 'sku' => 'BREADTH-1',
            'price_minor' => 2500, 'compare_price_minor' => 3000, 'currency' => 'USD', 'status' => 'active',
        ]);
        $this->products->storeImage(['id' => 'breadth-image-1', 'product_id' => 'breadth-product-1', 'url' => '/storage/uploads/lamp.png', 'storage_path' => 'uploads/lamp.png', 'sort' => 0]);
        Database::table('inventory_items')->insert([
            'id' => 'breadth-inv-1', 'variant_id' => 'breadth-variant-1', 'source_id' => 'default',
            'on_hand' => 7, 'reserved' => 2, 'low_threshold' => 2,
        ]);

        $export = $this->products->exportAdminCsvRows($this->scope);
        self::assertSame(1, $export['total']);
        self::assertFalse($export['truncated']);
        self::assertSame('BREADTH-1', $export['rows'][0]['sku'] ?? null);
        self::assertSame('lighting', $export['rows'][0]['category'] ?? null);
        self::assertSame(5, $export['rows'][0]['stock'] ?? null);

        self::assertCount(1, $this->products->brands());
        self::assertCount(1, $this->products->categories());
        self::assertCount(2, $this->products->categories(true));
        self::assertSame([['slug' => 'new', 'count' => 1], ['slug' => 'sale', 'count' => 1]], $this->products->tags($this->scope));
        self::assertSame('cat-1', $this->products->categoryIdBySlug('LIGHTING'));
        self::assertNull($this->products->categoryIdBySlug('missing'));

        $updated = $this->products->updateProduct('breadth-product-1', ['name' => 'Breadth Lamp v2', 'version' => 1, 'min_tier' => 'gold']);
        self::assertSame('Breadth Lamp v2', $updated['name'] ?? null);
        self::assertSame('gold', $updated['min_tier'] ?? null);
        try {
            $this->products->updateProduct('breadth-product-1', ['name' => 'Stale', 'version' => 1]);
            self::fail('Stale product versions must conflict.');
        } catch (CatalogConflict) {
            self::assertTrue(true);
        }
        try {
            $this->products->updateProduct('breadth-product-1', ['min_tier' => 'bogus']);
            self::fail('Invalid member tiers must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('min_tier', $error->getMessage());
        }
        self::assertNull($this->products->updateProduct('missing-product', ['name' => 'x']));

        Storage::put('uploads/lamp.png', 'image');
        self::assertTrue($this->products->deleteProduct('breadth-product-1'));
        self::assertFalse(Storage::exists('uploads/lamp.png'));
        self::assertFalse($this->products->deleteProduct('missing-product'));
    }

    public function testVariantsComponentsOptionsImagesAndDigitalAssets(): void
    {
        $this->products->store([
            'id' => 'owner-product', 'name' => 'Owner Product', 'slug' => 'owner-product', 'status' => 'active',
            'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD', 'allow_backorder' => 1,
        ]);
        $this->products->storeVariant([
            'id' => 'owner-variant', 'product_id' => 'owner-product', 'sku' => 'OWNER-1', 'price_minor' => 1000,
            'currency' => 'USD', 'status' => 'active',
        ]);
        $this->products->storeImage(['id' => 'owner-image', 'product_id' => 'owner-product', 'url' => '/storage/uploads/owner.png', 'sort' => 0]);
        Database::table('inventory_items')->insert([
            'id' => 'owner-inv', 'variant_id' => 'owner-variant', 'source_id' => 'default',
            'on_hand' => 4, 'reserved' => 1, 'low_threshold' => 1,
        ]);

        $this->products->store([
            'id' => 'bundle-product', 'name' => 'Bundle Product', 'slug' => 'bundle-product', 'status' => 'active',
            'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD',
            'bundle_pricing_mode' => 'component_sum', 'bundle_price_minor' => null,
        ]);
        $this->products->storeVariant([
            'id' => 'bundle-variant', 'product_id' => 'bundle-product', 'sku' => 'BUNDLE-1', 'price_minor' => 1200,
            'currency' => 'USD', 'status' => 'active',
        ]);
        $this->products->storeComponent([
            'product_id' => 'bundle-product', 'component_variant_id' => 'owner-variant', 'qty' => 2,
            'substitution_variant_ids' => '["owner-variant"]',
        ]);
        $this->products->storeComponent([
            'product_id' => 'bundle-product', 'component_variant_id' => 'missing-variant', 'qty' => 1,
        ]);

        $components = $this->products->components('bundle-product');
        self::assertCount(2, $components);
        self::assertSame(['owner-variant'], $components[0]['substitution_variant_ids']);

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $bundles = $this->products->storefrontBundleComponents('bundle-product');
        } finally {
            restore_error_handler();
        }
        self::assertCount(1, $bundles);
        self::assertSame('Owner Product', $bundles[0]['name']);
        self::assertSame(2, $bundles[0]['quantity']);
        self::assertSame(1000, $bundles[0]['price_minor']);
        self::assertSame('/storage/uploads/owner.png', $bundles[0]['image']);

        $grouped = $this->products->storefrontGroupedItems('bundle-product');
        self::assertCount(1, $grouped);
        self::assertSame('owner-variant', $grouped[0]['variant_id']);
        self::assertSame(3, $grouped[0]['stock']);
        self::assertTrue($grouped[0]['backorderable']);

        self::assertSame(['mode' => 'downloadable', 'state' => 'available'], $this->products->storefrontDelivery('downloadable'));
        self::assertSame(['mode' => 'virtual', 'state' => 'available'], $this->products->storefrontDelivery('virtual'));
        self::assertSame(['mode' => 'physical', 'state' => 'available'], $this->products->storefrontDelivery('simple'));

        $asset = $this->products->storeDigitalAsset([
            'variant_id' => 'owner-variant', 'name' => 'Manual', 'url' => '/downloads/manual.pdf',
            'access_key' => 'asset-key', 'download_limit' => 3,
        ]);
        self::assertSame('Manual', $this->products->digitalAsset((string) $asset['id'])['name'] ?? null);
        self::assertNull($this->products->digitalAsset('missing-asset'));

        $option = $this->products->storeOption(['id' => 'option-color', 'product_id' => 'owner-product', 'name' => 'Color', 'sort' => 1]);
        $value = $this->products->storeOptionValue(['id' => 'value-red', 'option_id' => 'option-color', 'value' => 'Red', 'sort' => 1, 'surcharge_minor' => 200, 'currency' => 'USD']);
        $this->products->storeVariantOption('owner-variant', 'option-color', 'value-red');
        Database::table('variant_quantity_prices')->insert([
            'id' => 'qty-price-1', 'variant_id' => 'owner-variant', 'minimum_qty' => 3, 'unit_price_minor' => 900, 'currency' => 'USD',
        ]);

        $options = $this->products->options('owner-product');
        self::assertCount(1, $options);
        self::assertSame('option-color', $options[0]['id']);
        self::assertCount(1, $options[0]['values']);
        self::assertTrue($this->products->optionBelongsToProduct('option-color', 'owner-product'));
        self::assertTrue($this->products->optionValuesBelongToProduct('owner-product', ['value-red']));
        self::assertFalse($this->products->optionValuesBelongToProduct('owner-product', ['value-blue']));
        self::assertTrue($this->products->optionValuesBelongToProduct('owner-product', []));

        $variants = $this->products->variants('owner-product');
        self::assertSame(['value-red'], $variants[0]['option_value_ids']);
        self::assertSame('qty-price-1', $variants[0]['quantity_prices'][0]['id'] ?? null);
        self::assertTrue($this->products->variantCombinationExists('owner-product', ['value-red']));
        self::assertFalse($this->products->variantCombinationExists('owner-product', ['value-blue']));
        self::assertFalse($this->products->variantCombinationExists('owner-product', []));

        self::assertSame([['name' => 'Color', 'values' => ['Red']]], $this->products->storefrontOptions($options));
        self::assertSame([['minimum' => 3, 'price' => 900, 'currency' => 'USD']], $this->products->storefrontQuantityPricing($variants, 'USD'));
        self::assertSame([['option' => 'Color', 'value' => 'Red', 'surcharge' => 200, 'currency' => 'USD']], $this->products->storefrontOptionPricing($options, 'USD'));

        $storefrontVariants = $this->products->storefrontVariantOptions($options, $variants, 'USD');
        self::assertSame('Red', $storefrontVariants[0]['options']['Color'] ?? null);
        self::assertSame(3, $storefrontVariants[0]['stock']);
        $prefetched = $this->products->storefrontVariantOptions($options, $variants, 'USD', ['owner-variant' => 9]);
        self::assertSame(9, $prefetched[0]['stock']);

        Database::table('product_attributes')->insert(['id' => 'attr-1', 'product_id' => 'owner-product', 'code' => 'material', 'value' => 'steel', 'value_type' => 'text']);
        self::assertSame('material', $this->products->attributes('owner-product')[0]['code'] ?? null);
        Database::table('variant_dependencies')->insert(['id' => 'dep-1', 'product_id' => 'owner-product', 'option_value_id' => 'value-red', 'depends_on_option_value_id' => 'value-red', 'operator' => 'eq']);
        self::assertCount(1, $this->products->dependencies('owner-product'));

        self::assertSame('OWNER-1', $this->products->findVariantBySku('OWNER-1')['sku'] ?? null);
        self::assertNull($this->products->findVariantBySku('MISSING'));
        $unchanged = $this->products->updateVariant('owner-variant', ['sku' => 'IGNORED']);
        self::assertSame('OWNER-1', $unchanged['sku'] ?? null);
        $priced = $this->products->updateVariant('owner-variant', ['price_minor' => 1500]);
        self::assertSame(1500, $priced['price_minor'] ?? null);
        self::assertSame(4, $this->products->variantOnHand('owner-variant'));
        self::assertSame(0, $this->products->variantOnHand('missing-variant'));

        $images = $this->products->images('owner-product');
        self::assertCount(1, $images);
        $extra = $this->products->storeImage([
            'id' => 'owner-image-2', 'product_id' => 'owner-product', 'url' => '/storage/uploads/owner2.png', 'sort' => 1,
            'variants_json' => json_encode([['w' => 320, 'url' => '/storage/uploads/owner2_320.webp', 'format' => 'webp']], JSON_THROW_ON_ERROR),
            'placeholder' => 'data:image/webp;base64,abc',
        ]);
        $images = $this->products->images('owner-product');
        self::assertCount(2, $images);
        self::assertSame('/storage/uploads/owner2_320.webp', $images[1]['variants'][0]['url'] ?? null);
        self::assertSame('data:image/webp;base64,abc', $images[1]['placeholder'] ?? null);
        try {
            $this->products->reorderImages('owner-product', ['owner-image']);
            self::fail('Reordering must include every media id.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->products->reorderImages('owner-product', ['owner-image-2', 'owner-image']);
        self::assertSame(0, $this->products->images('owner-product')[0]['sort']);
        self::assertNull($this->products->deleteImage('owner-product', 'missing-image'));
        self::assertNotNull($this->products->deleteImage('owner-product', (string) $extra['id']));
    }

    public function testSubscriptionPlanLinksForProducts(): void
    {
        Database::table('subscription_plans')->insert([
            'id' => 'plan-active', 'name' => 'Monthly', 'amount_minor' => 1200, 'currency' => 'usd',
            'interval' => 'monthly', 'interval_count' => 1, 'trial_period' => null, 'trial_period_count' => 0,
            'state' => 'active', 'provider' => null, 'metadata_json' => '{}', 'provider_authorization_state' => 'authorized',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Database::table('subscription_plans')->insert([
            'id' => 'plan-disabled', 'name' => 'Disabled', 'amount_minor' => 900, 'currency' => 'usd',
            'interval' => 'monthly', 'interval_count' => 1, 'trial_period' => null, 'trial_period_count' => 0,
            'state' => 'disabled', 'provider' => null, 'metadata_json' => '{}', 'provider_authorization_state' => 'authorized',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->products->store([
            'id' => 'plan-product', 'name' => 'Plan Product', 'slug' => 'plan-product', 'status' => 'active',
            'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD',
        ]);

        try {
            $this->products->syncSubscriptionPlans('plan-product', [123]);
            self::fail('Plan ids must be strings.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $this->products->syncSubscriptionPlans('plan-product', ['plan-disabled']);
            self::fail('Disabled plans must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('unknown or disabled', $error->getMessage());
        }
        $this->products->syncSubscriptionPlans('plan-product', ['plan-active', 'plan-active', '']);
        self::assertSame(['plan-active'], $this->products->subscriptionPlanIdsForProduct('plan-product'));
        $plans = $this->products->subscriptionPlansForProduct('plan-product');
        self::assertCount(1, $plans);
        self::assertSame('Monthly', $plans[0]['name']);
        self::assertSame(1200, $plans[0]['amount_minor']);
        self::assertSame([], $this->products->subscriptionPlansForProduct('missing-product'));
        self::assertSame([], $this->products->subscriptionPlanIdsForProduct('missing-product'));
        $this->products->syncSubscriptionPlans('plan-product', []);
        self::assertSame([], $this->products->subscriptionPlanIdsForProduct('plan-product'));
    }
}
