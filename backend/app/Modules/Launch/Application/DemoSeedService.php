<?php

declare(strict_types=1);

namespace App\Modules\Launch\Application;

use App\Modules\Launch\Domain\LaunchSeedRefused;
use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use PDO;

/**
 * Idempotent launch demo data.
 *
 * Records are matched by deterministic demo slugs/SKUs, so a rerun creates
 * nothing. Existing operator data is never updated or deleted. The seed
 * refuses to run when the catalog already looks like a real store.
 */
final class DemoSeedService
{
    public const MAX_ACTIVE_PRODUCTS = 50;

    /** @var array<string, array<string, true>> */
    private array $columns = [];

    /** @return array{created: array<string, int>, skipped: array<string, int>} */
    public function seed(): array
    {
        $pdo = $this->pdo();
        $active = $this->scalar($pdo, "SELECT COUNT(*) FROM products WHERE status = 'active'");
        if (is_numeric($active) && (int) $active > self::MAX_ACTIVE_PRODUCTS) {
            throw LaunchSeedRefused::activeCatalogTooLarge((int) $active, self::MAX_ACTIVE_PRODUCTS);
        }

        $created = $this->emptyCounts();
        $skipped = $this->emptyCounts();

        $pdo->beginTransaction();
        try {
            $this->seedTheme($pdo, $created, $skipped);
            $categoryIds = $this->seedCategories($pdo, $created, $skipped);
            $this->seedProducts($pdo, $categoryIds, $created, $skipped);
            $this->seedPages($pdo, $created, $skipped);
            $this->seedPrimaryMenu($pdo, $created, $skipped);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [
            'theme' => 0,
            'categories' => 0,
            'products' => 0,
            'variants' => 0,
            'inventory' => 0,
            'prices' => 0,
            'pages' => 0,
            'menus' => 0,
            'menu_items' => 0,
        ];
    }

    /**
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     * @param-out array<string, int> $created
     * @param-out array<string, int> $skipped
     */
    private function seedTheme(PDO $pdo, array &$created, array &$skipped): void
    {
        $now = date('Y-m-d H:i:s');
        $config = StorefrontConfigDefaults::config('default', 'default', 'en', 'VND');
        $theme = $config['theme'];
        $tokenSchema = [
            'colors' => ['hex' => true],
            'typography' => ['display' => ['editorial'], 'body' => ['system']],
            'shape' => ['radius' => ['soft'], 'card' => ['bordered']],
        ];
        $themeCreated = $this->insertIfMissing($pdo, 'storefront_themes', ['key' => 'editorial', 'version' => '1.0.0'], [
            'name' => 'Editorial',
            'schema_version' => 1,
            'status' => 'active',
            'token_schema_json' => json_encode($tokenSchema, JSON_THROW_ON_ERROR),
            'allowed_section_types_json' => json_encode(StorefrontConfigDefaults::allowedSections(), JSON_THROW_ON_ERROR),
            'defaults_json' => json_encode($theme, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->bump($themeCreated, $created, $skipped, 'theme');

        $presentation = ['product_card_variant' => 'editorial', 'product_detail_variant' => 'editorial', 'purchase_variant' => 'standard'];
        $presetCreated = $this->insertIfMissing($pdo, 'storefront_vertical_presets', ['key' => 'general', 'version' => '1.0.0'], [
            'name' => 'General',
            'status' => 'active',
            'theme_defaults_json' => '{}',
            'presentation_defaults_json' => json_encode($presentation, JSON_THROW_ON_ERROR),
            'homepage_defaults_json' => json_encode(['preferred_sections' => [], 'sections' => StorefrontConfigDefaults::defaultSections()], JSON_THROW_ON_ERROR),
            'validation_schema_json' => json_encode(['purchase_defaults' => ['quote_mode' => 'required']], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->bump($presetCreated, $created, $skipped, 'theme');

        $configCreated = $this->insertIfMissing($pdo, 'storefront_configs', [
            'store_id' => 'default',
            'channel_id' => 'default',
            'locale' => 'en',
            'currency' => 'VND',
        ], [
            'theme_key' => 'editorial',
            'vertical_key' => 'general',
            'status' => 'published',
            'version' => 1,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->bump($configCreated, $created, $skipped, 'theme');
    }

    /**
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     * @param-out array<string, int> $created
     * @param-out array<string, int> $skipped
     * @return array<string, string>
     */
    private function seedCategories(PDO $pdo, array &$created, array &$skipped): array
    {
        $definitions = [
            'demo-apparel' => 'Demo Apparel',
            'demo-home-living' => 'Demo Home & Living',
            'demo-personal-care' => 'Demo Personal Care',
        ];
        $ids = [];
        $sort = 1;
        foreach ($definitions as $slug => $name) {
            $id = $this->id('lcat', $slug);
            $inserted = $this->insertIfMissing($pdo, 'categories', ['slug' => $slug], [
                'id' => $id,
                'parent_id' => null,
                'name' => $name,
                'slug' => $slug,
                'sort' => $sort++,
            ]);
            $this->bump($inserted, $created, $skipped, 'categories');
            $existing = $this->lookupId($pdo, 'categories', 'slug', $slug, 'id');
            $ids[$slug] = $existing ?? $id;
        }
        return $ids;
    }

    /**
     * @param array<string, string> $categoryIds
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     * @param-out array<string, int> $created
     * @param-out array<string, int> $skipped
     */
    private function seedProducts(PDO $pdo, array $categoryIds, array &$created, array &$skipped): void
    {
        $now = date('Y-m-d H:i:s');
        $products = [
            ['slug' => 'demo-linen-shirt', 'name' => 'Demo Linen Shirt', 'sku' => 'DEMO-LINEN-SHIRT', 'category' => 'demo-apparel', 'price' => 890000, 'description' => 'A relaxed linen layer for warm-weather days.'],
            ['slug' => 'demo-wool-scarf', 'name' => 'Demo Wool Scarf', 'sku' => 'DEMO-WOOL-SCARF', 'category' => 'demo-apparel', 'price' => 650000, 'description' => 'A soft woven scarf for cooler evenings.'],
            ['slug' => 'demo-ceramic-mug', 'name' => 'Demo Ceramic Mug', 'sku' => 'DEMO-CERAMIC-MUG', 'category' => 'demo-home-living', 'price' => 290000, 'description' => 'Hand-finished stoneware mug for everyday coffee.'],
            ['slug' => 'demo-linen-throw', 'name' => 'Demo Linen Throw', 'sku' => 'DEMO-LINEN-THROW', 'category' => 'demo-home-living', 'price' => 1250000, 'description' => 'Breathable linen throw with a soft washed finish.'],
            ['slug' => 'demo-face-serum', 'name' => 'Demo Face Serum', 'sku' => 'DEMO-FACE-SERUM', 'category' => 'demo-personal-care', 'price' => 540000, 'description' => 'A lightweight daily serum with a simple ingredient list.'],
            ['slug' => 'demo-hand-balm', 'name' => 'Demo Hand Balm', 'sku' => 'DEMO-HAND-BALM', 'category' => 'demo-personal-care', 'price' => 220000, 'description' => 'Nourishing hand balm in a pocket-friendly tin.'],
        ];

        foreach ($products as $position => $product) {
            $productId = $this->id('lprod', $product['slug']);
            $inserted = $this->insertIfMissing($pdo, 'products', ['slug' => $product['slug']], [
                'id' => $productId,
                'name' => $product['name'],
                'slug' => $product['slug'],
                'category_id' => $categoryIds[$product['category']] ?? null,
                'status' => 'active',
                'description' => $product['description'],
                'tags_json' => json_encode(['demo', 'launch'], JSON_THROW_ON_ERROR),
                'allow_backorder' => 0,
                'type' => 'simple',
                'store_id' => 'default',
                'channel_id' => 'default',
                'locale' => 'en',
                'currency' => 'VND',
                'publish_state' => 'published',
                'approval_status' => 'approved',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bump($inserted, $created, $skipped, 'products');
            $existingProductId = $this->lookupId($pdo, 'products', 'slug', $product['slug'], 'id') ?? $productId;

            $variantId = $this->id('lvar', $product['sku']);
            $variantInserted = $this->insertIfMissing($pdo, 'product_variants', ['sku' => $product['sku']], [
                'id' => $variantId,
                'product_id' => $existingProductId,
                'sku' => $product['sku'],
                'price_minor' => $product['price'],
                'compare_price_minor' => null,
                'tax_class' => 'standard',
                'weight_g' => 300,
                'currency' => 'VND',
                'status' => 'active',
            ]);
            $this->bump($variantInserted, $created, $skipped, 'variants');
            if ($variantInserted) {
                $created['prices']++;
            } else {
                $skipped['prices']++;
            }
            $existingVariantId = $this->lookupId($pdo, 'product_variants', 'sku', $product['sku'], 'id') ?? $variantId;

            $inventoryInserted = $this->insertIfMissing($pdo, 'inventory_items', ['variant_id' => $existingVariantId, 'source_id' => 'default'], [
                'id' => $this->id('linv', $product['sku']),
                'variant_id' => $existingVariantId,
                'source_id' => 'default',
                'on_hand' => 25 + $position,
                'reserved' => 0,
                'low_threshold' => 5,
            ]);
            $this->bump($inventoryInserted, $created, $skipped, 'inventory');
        }
    }

    /**
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     * @param-out array<string, int> $created
     * @param-out array<string, int> $skipped
     */
    private function seedPages(PDO $pdo, array &$created, array &$skipped): void
    {
        $now = date('Y-m-d H:i:s');
        $pages = [
            'shipping' => ['Shipping policy', '<p>Orders dispatch within two business days. Tracking is emailed as soon as the parcel leaves.</p>'],
            'returns' => ['Returns policy', '<p>Eligible items can be returned within 30 days of delivery in original condition.</p>'],
            'contact' => ['Contact us', '<p>Questions about an order? Reply to your confirmation email or use the contact form.</p>'],
            'privacy' => ['Privacy policy', '<p>We store only what is needed to fulfil orders and support your account. Consent choices are recorded as pseudonymous session hashes, never as raw IP addresses or personal data.</p>'],
            'cookie-policy' => ['Cookie policy', '<p>Necessary storage keeps the cart and session working. Analytics and marketing storage stay off until you explicitly opt in. Change your choice at any time from the footer.</p>'],
        ];
        foreach ($pages as $slug => [$title, $body]) {
            $inserted = $this->insertIfMissing($pdo, 'cms_pages', ['slug' => $slug, 'locale' => 'en'], [
                'id' => $this->id('lpage', $slug),
                'slug' => $slug,
                'title' => $title,
                'body' => $body,
                'status' => 'published',
                'locale' => 'en',
                'published_at' => $now,
                'version' => 1,
                'store_id' => 'default',
                'channel_id' => 'default',
                'currency' => 'VND',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bump($inserted, $created, $skipped, 'pages');
        }
    }

    /**
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     * @param-out array<string, int> $created
     * @param-out array<string, int> $skipped
     */
    private function seedPrimaryMenu(PDO $pdo, array &$created, array &$skipped): void
    {
        $items = [
            ['label' => 'Home', 'target' => '/', 'sort' => 1],
            ['label' => 'Products', 'target' => '/products', 'sort' => 2],
            ['label' => 'Shipping', 'target' => '/shipping', 'sort' => 3],
            ['label' => 'Contact', 'target' => '/contact', 'sort' => 4],
        ];

        $menuId = $this->id('lmenu', 'primary');
        $inserted = $this->insertIfMissing($pdo, 'cms_menus', ['key' => 'primary'], [
            'id' => $menuId,
            'key' => 'primary',
            'locale' => 'en',
            'status' => 'published',
            'version' => 1,
        ]);
        $this->bump($inserted, $created, $skipped, 'menus');
        if (!$inserted) {
            $skipped['menu_items'] += count($items);
            return;
        }

        foreach ($items as $item) {
            $createdItem = $this->insertIfMissing($pdo, 'cms_menu_items', ['menu_id' => $menuId, 'label' => $item['label']], [
                'id' => $this->id('lmenu', 'primary-' . $item['label']),
                'menu_id' => $menuId,
                'label' => $item['label'],
                'target' => $item['target'],
                'target_type' => 'path',
                'parent_id' => null,
                'sort' => $item['sort'],
                'status' => 'published',
            ]);
            $this->bump($createdItem, $created, $skipped, 'menu_items');
        }
    }

    /**
     * @param array<string, scalar|null> $lookup
     * @param array<string, scalar|null> $data
     */
    private function insertIfMissing(PDO $pdo, string $table, array $lookup, array $data): bool
    {
        $where = implode(' AND ', array_map(static fn (string $key): string => '`' . $key . '` = ?', array_keys($lookup)));
        $statement = $pdo->prepare('SELECT 1 FROM `' . $table . '` WHERE ' . $where . ' LIMIT 1');
        $statement->execute(array_values($lookup));
        if ($statement->fetchColumn() !== false) {
            return false;
        }

        $columns = $this->columns($pdo, $table);
        $row = array_merge($lookup, $data);
        $filtered = array_filter($row, static fn (string $key): bool => isset($columns[$key]), ARRAY_FILTER_USE_KEY);
        if ($filtered === []) {
            return false;
        }
        $names = array_keys($filtered);
        $quoted = array_map(static fn (string $name): string => '`' . $name . '`', $names);
        $insert = $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $insert->execute(array_values($filtered));
        return true;
    }

    /** @return array<string, true> */
    private function columns(PDO $pdo, string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $rawDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($rawDriver) ? strtolower($rawDriver) : '';
        $statement = $driver === 'mysql'
            ? $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')
            : $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
        $columns = [];
        if ($statement === false) {
            return $this->columns[$table] = [];
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = $row['Field'] ?? $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $columns[$name] = true;
            }
        }
        return $this->columns[$table] = $columns;
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        try {
            $statement = $pdo->query($sql);
            if ($statement === false) {
                return 0;
            }
            $value = $statement->fetchColumn();
            return $value === false ? 0 : $value;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function lookupId(PDO $pdo, string $table, string $column, string $value, string $idColumn): ?string
    {
        try {
            $statement = $pdo->prepare('SELECT `' . $idColumn . '` FROM `' . $table . '` WHERE `' . $column . '` = ? LIMIT 1');
            $statement->execute([$value]);
            $id = $statement->fetchColumn();
            return is_string($id) && $id !== '' ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, int> $created
     * @param array<string, int> $skipped
     * @param-out array<string, int> $created
     * @param-out array<string, int> $skipped
     */
    private function bump(bool $inserted, array &$created, array &$skipped, string $key): void
    {
        if ($inserted) {
            $created[$key]++;
        } else {
            $skipped[$key]++;
        }
    }

    private function id(string $prefix, string $seed): string
    {
        return substr($prefix . '_' . hash('sha256', $seed), 0, 26);
    }

    private function pdo(): PDO
    {
        return \Siro\Core\Database::connection();
    }
}
