<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class B2cCatalogSeeder
{
    private const STORE_ID = 'default';
    private const CHANNEL_ID = 'web';

    private const SCOPES = [
        ['locale' => 'en', 'currency' => 'USD', 'id_prefix' => 'b2c-prod-', 'sku_prefix' => 'B2C-', 'price_list' => 'b2c-retail-usd', 'compare_step' => [1400, 800], 'price_field' => 0],
        ['locale' => 'en', 'currency' => 'VND', 'id_prefix' => 'b2c-prod-vnd-', 'sku_prefix' => 'B2C-VND-', 'price_list' => 'b2c-retail-vnd', 'compare_step' => [50000, 30000], 'price_field' => 1],
    ];

    /** @return array<string, int> */
    public function seed(PDO $pdo): array
    {
        $tables = $this->tables($pdo);
        foreach (['stores', 'channels', 'categories', 'products', 'product_variants', 'product_images', 'inventory_items'] as $table) {
            if (!in_array($table, $tables, true)) throw new RuntimeException("B2C seeding requires table: {$table}");
        }

        $now = date('Y-m-d H:i:s');
        $counts = ['stores' => 0, 'channels' => 0, 'categories' => 0, 'products' => 0, 'variants' => 0, 'media' => 0, 'inventory' => 0, 'prices' => 0, 'taxes' => 0];
        // The catalog is fully derived from catalog(): wipe the seeded scope
        // first so a slug that moves between products can never orphan or
        // duplicate a row under the scope+slug unique key.
        $this->wipeSeededScope($pdo);
        if (in_array('tax_rules', $tables, true)) {
            $counts['taxes'] += $this->upsert($pdo, 'tax_rules', ['id' => 'b2c-tax-default', 'code' => 'b2c-default', 'tax_class' => 'standard', 'rate_basis_points' => 0, 'source' => 'native', 'evidence' => 'Default B2C sample-store schedule', 'priority' => 0, 'state' => 'active']);
        }
        $pdo->beginTransaction();
        try {
            $counts['stores'] += $this->upsert($pdo, 'stores', ['id' => self::STORE_ID, 'code' => 'default', 'name' => 'Default Store', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            $counts['channels'] += $this->upsert($pdo, 'channels', ['id' => self::CHANNEL_ID, 'store_id' => self::STORE_ID, 'code' => 'web', 'name' => 'Web Storefront', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

            $categories = [
                ['id' => 'b2c-cat-thoi-trang', 'name' => 'Apparel', 'slug' => 'apparel'],
                ['id' => 'b2c-cat-nha-cua', 'name' => 'Home & Living', 'slug' => 'home-living'],
                ['id' => 'b2c-cat-my-pham', 'name' => 'Personal Care', 'slug' => 'personal-care'],
                ['id' => 'b2c-cat-do-uong', 'name' => 'Food & Beverage', 'slug' => 'food-beverage'],
            ];
            foreach ($categories as $index => $category) {
                $counts['categories'] += $this->upsert($pdo, 'categories', $category + ['sort' => $index + 1, 'created_at' => $now, 'updated_at' => $now]);
            }

            $products = self::catalog();
            foreach ($products as $index => $entry) {
                [$slug, $categorySlug, $tags, $name, $priceUsd, $priceVnd] = $entry;
                /** @var array<string, mixed> $meta */
                $meta = $entry[6] ?? [];
                $type = (string) ($meta['type'] ?? 'simple');
                $categoryId = '';
                foreach ($categories as $category) if ($category['slug'] === $categorySlug) { $categoryId = $category['id']; break; }
                if ($categoryId === '') throw new RuntimeException("Unknown B2C category: {$categorySlug}");
                $prices = [$priceUsd, $priceVnd];
                foreach (self::SCOPES as $scope) {
                    $productId = $scope['id_prefix'] . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                    $price = $prices[$scope['price_field']];
                    $status = (string) ($meta['status'] ?? 'active');
                    $counts['products'] += $this->upsert($pdo, 'products', [
                        'id' => $productId, 'name' => $name, 'slug' => $slug, 'status' => $status, 'publish_state' => 'published', 'publish_at' => $now, 'type' => $type,
                        'store_id' => self::STORE_ID, 'channel_id' => self::CHANNEL_ID, 'locale' => $scope['locale'], 'currency' => $scope['currency'],
                        'category_id' => $categoryId, 'description' => $this->description($slug),
                        'tags_json' => json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'allow_backorder' => !empty($meta['backorder']) ? 1 : 0,
                        'meta_title' => $name . ' | Default Store', 'meta_description' => 'Shop the ' . strtolower($name) . ' with dependable quality and convenient delivery.', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $variantIds = $this->seedVariants($pdo, $counts, $scope, $productId, $slug, $name, $index, $price, (string) $scope['currency'], $meta, $now);
                    $angles = ['front' => 'Front view', 'angle' => 'Angled view', 'detail' => 'Detail view', 'alt' => 'Alternate view'];
                    // Retired single-frame media must not outrank the gallery.
                    $legacy = $pdo->prepare('DELETE FROM product_images WHERE product_id = ? AND id = ?');
                    $legacy->execute([$productId, $productId . '-img']);
                    $position = 0;
                    foreach ($angles as $angleSlug => $angleLabel) {
                        $counts['media'] += $this->upsert($pdo, 'product_images', ['id' => $productId . '-img-' . $angleSlug, 'product_id' => $productId, 'url' => '/catalog/b2c/' . $slug . '-' . $angleSlug . '.svg', 'alt' => $name . ' - ' . strtolower($angleLabel), 'sort' => $position++, 'type' => 'image', 'mime_type' => 'image/svg+xml', 'role' => 'gallery', 'created_at' => $now, 'updated_at' => $now]);
                    }
                    $counts['inventory'] += $this->seedInventory($pdo, $productId, $variantIds, $index, $meta);
                    if (in_array('pricing_price_lists', $tables, true) && in_array('pricing_price_list_prices', $tables, true)) {
                         $this->upsert($pdo, 'pricing_price_lists', ['id' => $scope['price_list'], 'code' => $scope['price_list'], 'name' => 'Web Retail Prices', 'currency' => $scope['currency'], 'customer_group_id' => null, 'priority' => 0, 'state' => 'active']);
                        foreach ($variantIds as $pricedVariantId => $variantPrice) $counts['prices'] += $this->upsert($pdo, 'pricing_price_list_prices', ['id' => $pricedVariantId . '-price', 'price_list_id' => $scope['price_list'], 'variant_id' => $pricedVariantId, 'unit_price_minor' => $variantPrice]);
                    }
                    $this->seedRelations($pdo, $scope, $productId, $slug, $name, $index, $price, (string) $scope['currency'], $meta, $now);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $counts;
    }

    /**
     * English sample catalog: slug, category, tags, name, USD minor, VND minor.
     *
     * @return list<array{0:string,1:string,2:list<string>,3:string,4:int,5:int}>
     */
    public static function catalog(): array
    {
        return [
            ['linen-mandarin-shirt', 'apparel', ['linen', 'summer', 'bestseller'], 'Linen Mandarin-Collar Shirt', 6800, 329000, ['type' => 'configurable', 'sizes' => ['S' => -200, 'M' => 0, 'L' => 300], 'sizes_vnd' => ['S' => -3000, 'M' => 0, 'L' => 5000], 'colors' => ['Natural' => 0, 'Charcoal' => 0]]],
            ['organic-cotton-crew-tee', 'apparel', ['cotton', 'everyday'], 'Organic Cotton Crew Tee', 3200, 289000],
            ['rigid-indigo-denim-trousers', 'apparel', ['denim', 'organic'], 'Rigid Indigo Denim Trousers', 8900, 799000],
            ['melange-wool-overshirt', 'apparel', ['wool', 'layering'], 'Melange Wool Overshirt', 12900, 1150000],
            ['linen-tie-front-blouse', 'apparel', ['linen', 'layering'], 'Linen Tie Front Blouse', 5400, 469000],
            ['packable-rope-sun-hat', 'apparel', ['sun protection'], 'Packable Rope-Sun Hat', 2900, 259000, ['stock' => 0, 'backorder' => true]],
            ['structured-canvas-tote', 'apparel', ['canvas', 'minimalist', 'new'], 'Structured Canvas Tote Bag', 4400, 189000],
            ['lined-leather-utility-sling', 'apparel', ['leather', 'workwear'], 'Lined Leather Utility Sling', 5900, 549000],
            ['natural-latex-mattress', 'home-living', ['bedroom', 'comfort'], 'Natural Latex Mattress', 12900, 1290000, ['status' => 'out_of_stock', 'stock' => 0]],
            ['stainless-steel-flask-500ml', 'home-living', ['everyday carry', 'reusable'], '500ml Stainless Steel Flask', 2400, 249000],
            ['hand-thrown-stoneware-bowl', 'home-living', ['ceramic', 'handmade'], 'Hand-Thrown Stoneware Bowl', 3800, 349000],
            ['stonewashed-linen-bedding-set', 'home-living', ['linen', 'bedroom'], 'Stonewashed Linen Bedding Set', 7900, 749000],
            ['fsc-oak-desk-tray', 'home-living', ['fsc', 'organiser'], 'FSC Oak Desk Tray', 5800, 549000],
            ['speckled-stoneware-serving-jug', 'home-living', ['ceramic', 'tabletop'], 'Speckled Stoneware Serving Jug', 4200, 389000],
            ['brushed-wool-throw-blanket', 'home-living', ['wool', 'living'], 'Brushed Wool Throw Blanket', 9600, 890000],
            ['gentle-green-tea-facial-cleanser', 'personal-care', ['green tea', 'sensitive skin'], 'Gentle Green Tea Facial Cleanser', 1600, 159000],
            ['broad-spectrum-spf50-sunscreen', 'personal-care', ['sun protection', 'bestseller'], 'Broad-Spectrum SPF 50 Sunscreen', 2800, 279000],
            ['calming-rosehip-body-wash', 'personal-care', ['bath', 'calming'], 'Calming Rosehip Body Wash', 1900, 189000],
            ['shea-repair-hand-cream', 'personal-care', ['shea', 'dry skin'], 'Shea Repair Hand Cream', 1400, 139000],
            ['refillable-bamboo-toothbrush-set', 'personal-care', ['sustainable', 'refillable'], 'Refillable Bamboo Toothbrush Set', 1200, 119000],
            ['single-origin-arabica-coffee', 'food-beverage', ['arabica', 'small-batch roasted'], 'Single-Origin Arabica Coffee', 1500, 145000, ['tiers' => [[3, 1400, 135000], [6, 1250, 120000]]]],
            ['jasmine-oolong-tea', 'food-beverage', ['oolong', 'relaxation'], 'Jasmine Oolong Tea', 1200, 98000],
            ['ceremonial-grade-matcha', 'food-beverage', ['matcha', 'ceremony'], 'Ceremonial Grade Matcha', 3200, 299000],
            ['dried-lotus-seed-tea', 'food-beverage', ['herbal', 'caffeine free'], 'Dried Lotus Seed Tea', 1100, 89000],
            ['single-origin-cacao-drinking-chocolate', 'food-beverage', ['cacao', 'origin'], 'Single-Origin Cacao Drinking Chocolate', 2100, 199000],
            ['wildflower-honey-jar', 'food-beverage', ['honey', 'pantry'], 'Wildflower Honey Jar', 1700, 169000],
            ['morning-ritual-gift-set', 'food-beverage', ['gift set', 'bundle', 'ritual'], 'Morning Ritual Gift Set', 3900, 369000, ['type' => 'bundle', 'bundle' => ['jasmine-oolong-tea' => 1, 'single-origin-arabica-coffee' => 1, 'wildflower-honey-jar' => 1]]],
            ['home-brew-guide-ebook', 'food-beverage', ['guide', 'downloadable', 'brewing'], 'Home Brew Guide eBook', 900, 89000, ['type' => 'downloadable', 'digital' => [['Home Brew Guide (PDF)', 5]]]],
            ['linen-starter-set', 'apparel', ['set', 'grouped', 'starter'], 'Linen Starter Set', 4400, 189000, ['type' => 'grouped', 'grouped' => ['structured-canvas-tote', 'packable-rope-sun-hat']]],
            ['gift-wrapping-service', 'home-living', ['service', 'virtual', 'gift'], 'Gift Wrapping Service', 500, 49000, ['type' => 'virtual']],
        ];
    }

    /** @return list<array{slug:string,name:string,category:string}> */
    public static function imageAngles(): array
    {
        $angles = [
            ['slug' => 'front', 'name' => 'Front view'],
            ['slug' => 'angle', 'name' => 'Angled view'],
            ['slug' => 'detail', 'name' => 'Detail view'],
            ['slug' => 'alt', 'name' => 'Alternate view'],
        ];
        $images = [];
        foreach (self::catalog() as [$slug, , , $name]) {
            foreach ($angles as $angle) $images[] = ['slug' => $slug . '-' . $angle['slug'], 'name' => $name . ' - ' . $angle['name'], 'category' => $angle['slug']];
        }
        return $images;
    }

    private function description(string $slug): string
    {
        foreach (self::catalog() as [$productSlug, , , $name, , ]) {
            if ($productSlug === $slug) return self::productDescription($slug, $name);
        }
        throw new RuntimeException("Unknown B2C product: {$slug}");
    }

    private static function productDescription(string $slug, string $name): string
    {
        $descriptions = [
            'linen-mandarin-shirt' => 'A breathable linen shirt with a clean Mandarin collar for polished warm-weather dressing.',
            'organic-cotton-crew-tee' => 'A mid-weight organic cotton tee with a soft hand that keeps its shape wash after wash.',
            'rigid-indigo-denim-trousers' => 'A rigid indigo denim cut with a straight leg and a considered, work-ready finish.',
            'melange-wool-overshirt' => 'A brushed wool-cotton overshirt that layers cleanly over a tee or a fine knit.',
            'linen-tie-front-blouse' => 'A light linen blouse with a soft tie neckline for warm days and easy tailoring.',
            'packable-rope-sun-hat' => 'A wide-brim rope-sun hat that folds flat and keeps its shape in a bag.',
            'structured-canvas-tote' => 'A durable structured canvas tote with room for daily essentials and an easy-to-carry profile.',
            'lined-leather-utility-sling' => 'A vegetable-tanned leather sling with a linen lining that softens with wear.',
            'natural-latex-mattress' => 'A supportive natural latex mattress designed for balanced comfort and restful nights.',
            'stainless-steel-flask-500ml' => 'A compact 500ml stainless steel flask that keeps drinks ready for commutes and day trips.',
            'hand-thrown-stoneware-bowl' => 'A hand-thrown stoneware bowl with a satin glaze that hides marks and stacks easily.',
            'stonewashed-linen-bedding-set' => 'A stonewashed linen and cotton bedding set that breathes through warm nights.',
            'fsc-oak-desk-tray' => 'An FSC oak tray that keeps keys, coins, and daily small things in one place.',
            'speckled-stoneware-serving-jug' => 'A speckled stoneware jug sized for table service and everyday pouring.',
            'brushed-wool-throw-blanket' => 'A brushed wool throw that adds warmth to a chair without crowding a room.',
            'gentle-green-tea-facial-cleanser' => 'A gentle green tea facial cleanser that lifts daily residue without leaving skin feeling tight.',
            'broad-spectrum-spf50-sunscreen' => 'A broad-spectrum SPF 50 sunscreen for lightweight daily protection against UVA and UVB rays.',
            'calming-rosehip-body-wash' => 'A calming rosehip body wash that rinses clean and leaves skin comfortable.',
            'shea-repair-hand-cream' => 'A shea hand cream that absorbs quickly enough for typing and frequent washing.',
            'refillable-bamboo-toothbrush-set' => 'A refillable bamboo toothbrush set that reduces packaging without sacrificing bristle quality.',
            'single-origin-arabica-coffee' => 'A small-batch roasted Arabica coffee with a smooth body and a bright, balanced finish.',
            'jasmine-oolong-tea' => 'A fragrant jasmine oolong tea with a rounded finish for a calm afternoon ritual.',
            'ceremonial-grade-matcha' => 'A ceremonial grade matcha with a smooth grind for whisking and lattes alike.',
            'dried-lotus-seed-tea' => 'A caffeine-free lotus seed tea with a soft, slightly sweet finish.',
            'single-origin-cacao-drinking-chocolate' => 'A single-origin drinking chocolate with deep fruit and a clean, low bitterness.',
            'wildflower-honey-jar' => 'A small-batch wildflower honey with a floral aroma and a slow, honeyed finish.',
            'morning-ritual-gift-set' => 'A ready-to-give morning set: jasmine oolong, single-origin coffee, and wildflower honey in one box.',
            'home-brew-guide-ebook' => 'A downloadable home brew guide with step-by-step ratios for coffee, tea, and matcha.',
            'linen-starter-set' => 'A starter set pairing the linen shirt essentials. Choose each piece separately.',
            'gift-wrapping-service' => 'A virtual gift wrapping service. We wrap your order in linen paper before it ships.',
        ];
        return $descriptions[$slug] ?? $name . ' from the SiroCommerce sample catalog.';
    }

  /**
     * @param array<string, int> $counts
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $meta
     * @return array<string, int> variant id to unit price
     */
    private function seedVariants(PDO $pdo, array &$counts, array $scope, string $productId, string $slug, string $name, int $index, int $price, string $currency, array $meta, string $now): array
    {
        $variantIds = [];
        [$evenStep, $oddStep] = $scope['compare_step'];
        if (!empty($meta['sizes'])) {
            $sizes = (array) $meta['sizes'];
            $colors = (array) ($meta['colors'] ?? ['Natural' => 0]);
            $deltas = $scope['currency'] === 'VND' ? (array) ($meta['sizes_vnd'] ?? []) : [];
            $optionIds = [];
            foreach (['Size' => $sizes, 'Color' => $colors] as $optionName => $values) {
                $optionId = $productId . ($optionName === 'Size' ? '-os' : '-oc');
                $this->upsert($pdo, 'product_options', ['id' => $optionId, 'product_id' => $productId, 'name' => $optionName, 'sort' => $optionName === 'Size' ? 0 : 1, 'created_at' => $now, 'updated_at' => $now]);
                $optionIds[$optionName] = ['_id' => $optionId];
                $position = 0;
                foreach ($values as $value => $surcharge) {
                    $valueId = $optionId . '-' . strtolower(substr((string) $value, 0, 1)) . ($optionName === 'Color' ? strtolower(substr((string) $value, -1, 1)) : '');
                    $surchargeMinor = $optionName === 'Size' ? ($scope['currency'] === 'VND' ? (int) ($deltas[$value] ?? 0) : (int) $surcharge) : 0;
                    $this->upsert($pdo, 'product_option_values', ['id' => $valueId, 'option_id' => $optionId, 'value' => (string) $value, 'sort' => $position++, 'surcharge_minor' => $surchargeMinor, 'currency' => $currency, 'created_at' => $now, 'updated_at' => $now]);
                    $optionIds[$optionName][(string) $value] = $valueId;
                }
            }
            foreach ($sizes as $size => $sizeSurcharge) {
                $sizeDelta = $scope['currency'] === 'VND' ? (int) ($deltas[$size] ?? 0) : (int) $sizeSurcharge;
                foreach (array_keys($colors) as $color) {
                    // Compact variant ids: inventory, price and tier rows reuse the
                    // variant id, and every one of those keys is VARCHAR(26).
                    $variantId = $productId . '-' . strtolower(substr((string) $size, 0, 1)) . strtolower(substr((string) $color, 0, 1)) . strtolower(substr((string) $color, -1, 1));
                    $variantPrice = $price + $sizeDelta;
                    $sku = $scope['sku_prefix'] . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT) . '-' . strtoupper(substr((string) $size, 0, 1)) . '-' . strtoupper(substr((string) $color, 0, 3));
                    $counts['variants'] += $this->upsert($pdo, 'product_variants', ['id' => $variantId, 'product_id' => $productId, 'sku' => $sku, 'price_minor' => $variantPrice, 'compare_price_minor' => $variantPrice + ($index % 2 === 0 ? $evenStep : $oddStep), 'currency' => $currency, 'tax_class' => 'standard', 'weight_g' => 100 + $index * 25]);
                    foreach (['Size' => $size, 'Color' => $color] as $optionName => $optionValue) {
                        $this->insertIgnore($pdo, 'variant_option_values', ['variant_id' => $variantId, 'option_id' => $optionIds[$optionName]['_id'], 'option_value_id' => $optionIds[$optionName][(string) $optionValue]]);
                    }
                    $variantIds[$variantId] = $variantPrice;
                }
            }
            return $variantIds;
        }
        $variantId = $productId . '-std';
        $sku = $scope['sku_prefix'] . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT) . '-STD';
        $counts['variants'] += $this->upsert($pdo, 'product_variants', ['id' => $variantId, 'product_id' => $productId, 'sku' => $sku, 'price_minor' => $price, 'compare_price_minor' => $price + ($index % 2 === 0 ? $evenStep : $oddStep), 'currency' => $currency, 'tax_class' => 'standard', 'weight_g' => 100 + $index * 25]);
        foreach ((array) ($meta['tiers'] ?? []) as $tier) {
            [$minimum, $usdPrice, $vndPrice] = $tier;
            $tierPrice = $scope['currency'] === 'VND' ? (int) $vndPrice : (int) $usdPrice;
            $this->upsert($pdo, 'variant_quantity_prices', ['id' => $variantId . '-t' . $minimum, 'variant_id' => $variantId, 'minimum_qty' => (int) $minimum, 'unit_price_minor' => $tierPrice, 'currency' => $currency]);
        }
        return [$variantId => $price];
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, int> $variantIds
     * @param array<string, mixed> $meta
     */
    private function seedInventory(PDO $pdo, string $productId, array $variantIds, int $index, array $meta): int
    {
        $added = 0;
        $position = 0;
        foreach (array_keys($variantIds) as $variantId) {
            $onHand = array_key_exists('stock', $meta) ? (int) $meta['stock'] : 40 + $index * 7 + $position * 3;
            $added += $this->upsert($pdo, 'inventory_items', ['id' => $variantId . '-stock', 'variant_id' => $variantId, 'on_hand' => $onHand, 'reserved' => 0, 'low_threshold' => 5]);
            $position++;
        }
        return $added;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $meta
     */
    private function seedRelations(PDO $pdo, array $scope, string $productId, string $slug, string $name, int $index, int $price, string $currency, array $meta, string $now): void
    {
        $prefix = (string) $scope['id_prefix'];
        $variantFor = function (string $targetSlug, ?string $variantSuffix = null) use ($prefix): ?string {
            foreach (self::catalog() as $position => $row) {
                if ($row[0] === $targetSlug) {
                    $targetId = $prefix . str_pad((string) ($position + 1), 2, '0', STR_PAD_LEFT);
                    return $targetId . ($variantSuffix ?? '-std');
                }
            }
            return null;
        };
        $position = 0;
        foreach ((array) ($meta['bundle'] ?? []) as $componentSlug => $quantity) {
            $componentVariant = $variantFor((string) $componentSlug);
            if ($componentVariant === null) continue;
            $this->upsert($pdo, 'product_components', ['id' => $productId . '-c' . $position++, 'product_id' => $productId, 'component_variant_id' => $componentVariant, 'qty' => (int) $quantity, 'substitution_policy' => 'none']);
        }
        foreach ((array) ($meta['grouped'] ?? []) as $componentSlug) {
            $componentVariant = $variantFor((string) $componentSlug);
            if ($componentVariant === null) continue;
            $this->upsert($pdo, 'product_components', ['id' => $productId . '-c' . $position++, 'product_id' => $productId, 'component_variant_id' => $componentVariant, 'qty' => 1, 'substitution_policy' => 'none']);
        }
        foreach ((array) ($meta['digital'] ?? []) as $asset) {
            [$assetName, $limit] = $asset;
            $this->upsert($pdo, 'digital_assets', ['id' => $productId . '-std-pdf', 'variant_id' => $productId . '-std', 'name' => (string) $assetName, 'url' => '/catalog/b2c/' . $slug . '-front.svg', 'access_key' => substr(hash('sha256', $productId . $assetName), 0, 32), 'download_limit' => (int) $limit]);
        }
    }

   /** Remove every row this seeder owns so a re-run is a clean rebuild, not a merge. */
    private function wipeSeededScope(PDO $pdo): void
    {
        $prefixes = array_map(static fn (array $scope): string => (string) $scope['id_prefix'], self::SCOPES);
        $like = static fn (string $column) => implode(' OR ', array_map(static fn (string $prefix): string => "`{$column}` LIKE " . "'{$prefix}%'", $prefixes));
        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = strtolower(is_string($driverValue) ? $driverValue : '');
        $quote = static fn (string $table): string => $driver === 'mysql' ? "`{$table}`" : "\"{$table}\"";
        $statements = [
            'DELETE FROM ' . $quote('variant_option_values') . ' WHERE ' . $like('variant_id'),
            'DELETE FROM ' . $quote('variant_quantity_prices') . ' WHERE ' . $like('variant_id'),
            'DELETE FROM ' . $quote('product_option_values') . ' WHERE ' . $like('id'),
            'DELETE FROM ' . $quote('product_options') . ' WHERE ' . $like('id'),
            'DELETE FROM ' . $quote('product_components') . ' WHERE ' . $like('product_id'),
            'DELETE FROM ' . $quote('digital_assets') . ' WHERE ' . $like('variant_id'),
            'DELETE FROM ' . $quote('inventory_items') . ' WHERE ' . $like('variant_id'),
            'DELETE FROM ' . $quote('pricing_price_list_prices') . ' WHERE ' . $like('variant_id'),
            'DELETE FROM ' . $quote('product_images') . ' WHERE ' . $like('product_id'),
            'DELETE FROM ' . $quote('product_variants') . ' WHERE ' . $like('product_id'),
            'DELETE FROM ' . $quote('products') . ' WHERE ' . $like('id'),
        ];
        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // A fresh database may not have every table yet.
                if (!str_contains($e->getMessage(), 'no such table') && !str_contains(strtolower($e->getMessage()), "doesn't exist")) throw $e;
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function insertIgnore(PDO $pdo, string $table, array $data): void
    {
        $columns = $this->columns($pdo, $table);
        $data = array_intersect_key($data, array_flip($columns));
        $quoted = array_map(static fn (string $name): string => '`' . $name . '`', array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = strtolower(is_string($driverValue) ? $driverValue : '');
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . $placeholders . ')';
        $sql .= $driver === 'mysql' ? ' ON DUPLICATE KEY UPDATE `variant_id`=VALUES(`variant_id`)' : ' ON CONFLICT DO NOTHING';
        try {
            $pdo->prepare($sql)->execute(array_values($data));
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE') && !str_contains($e->getMessage(), 'Duplicate')) throw $e;
        }
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = strtolower(is_string($driverValue) ? $driverValue : '');
        $sql = $driver === 'mysql' ? 'SHOW TABLES' : "SELECT name FROM sqlite_master WHERE type='table'";
        $statement = $pdo->query($sql);
        if ($statement === false) throw new RuntimeException('Could not inspect tables.');
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $rows), static fn (string $value): bool => $value !== ''));
    }

    /** @param array<string, mixed> $data */
    private function upsert(PDO $pdo, string $table, array $data): int
    {
        $columns = $this->columns($pdo, $table);
        $data = array_intersect_key($data, array_flip($columns));
        if (!isset($data['id'])) throw new RuntimeException("B2C seeding cannot upsert {$table}");
        $names = array_keys($data);
        $quoted = array_map(static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`', $names);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $updates = implode(',', array_map(static fn (string $name): string => "{$name}=VALUES({$name})", array_slice($quoted, 1)));
        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = strtolower(is_string($driverValue) ? $driverValue : '');
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . $placeholders . ')';
        $sql .= $driver === 'mysql' ? ' ON DUPLICATE KEY UPDATE ' . $updates : ' ON CONFLICT(`id`) DO UPDATE SET ' . implode(',', array_map(static fn (string $name): string => "{$name}=excluded.{$name}", array_slice($quoted, 1)));
        $pdo->prepare($sql)->execute(array_values($data));
        return 1;
    }

    /** @return list<string> */
    private function columns(PDO $pdo, string $table): array
    {
        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = strtolower(is_string($driverValue) ? $driverValue : '');
        $sql = $driver === 'mysql' ? 'SHOW COLUMNS FROM `' . $table . '`' : 'PRAGMA table_info("' . $table . '")';
        $statement = $pdo->query($sql);
        if ($statement === false) throw new RuntimeException("Could not inspect columns for {$table}.");
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter(array_map(static function (mixed $row): string {
            if (!is_array($row)) return '';
            $value = $row['Field'] ?? $row['name'] ?? '';
            return is_string($value) ? $value : '';
        }, $rows), static fn (string $value): bool => $value !== ''));
    }
}
