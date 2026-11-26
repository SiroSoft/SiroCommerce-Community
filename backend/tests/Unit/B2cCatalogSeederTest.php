<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\B2cCatalogSeeder;
use PHPUnit\Framework\TestCase;
use PDO;

final class B2cCatalogSeederTest extends TestCase
{
    public function testB2cSeedIsRepeatableAndContainsOnlyTheRequestedScope(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE stores (id TEXT PRIMARY KEY, code TEXT UNIQUE, name TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE channels (id TEXT PRIMARY KEY, store_id TEXT, code TEXT, name TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE categories (id TEXT PRIMARY KEY, name TEXT, slug TEXT UNIQUE, sort INTEGER, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE products (id TEXT PRIMARY KEY, name TEXT, slug TEXT, status TEXT, publish_state TEXT, publish_at TEXT, type TEXT, store_id TEXT, channel_id TEXT, locale TEXT, currency TEXT, category_id TEXT, description TEXT, tags_json TEXT, allow_backorder INTEGER, meta_title TEXT, meta_description TEXT, created_at TEXT, updated_at TEXT, UNIQUE(store_id, channel_id, locale, currency, slug))');
        $pdo->exec('CREATE TABLE product_variants (id TEXT PRIMARY KEY, product_id TEXT, sku TEXT UNIQUE, price_minor INTEGER, compare_price_minor INTEGER, tax_class TEXT, weight_g INTEGER)');
        $pdo->exec('CREATE TABLE product_images (id TEXT PRIMARY KEY, product_id TEXT, url TEXT, alt TEXT, sort INTEGER, type TEXT, mime_type TEXT, role TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE inventory_items (id TEXT PRIMARY KEY, variant_id TEXT UNIQUE, on_hand INTEGER, reserved INTEGER, low_threshold INTEGER)');
        $pdo->exec('CREATE TABLE pricing_price_lists (id TEXT PRIMARY KEY, code TEXT UNIQUE, name TEXT, currency TEXT, customer_group_id TEXT, priority INTEGER, state TEXT)');
        $pdo->exec('CREATE TABLE pricing_price_list_prices (id TEXT PRIMARY KEY, price_list_id TEXT, variant_id TEXT, unit_price_minor INTEGER)');
        $pdo->exec('CREATE TABLE product_options (id TEXT PRIMARY KEY, product_id TEXT, name TEXT, sort INTEGER, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE product_option_values (id TEXT PRIMARY KEY, option_id TEXT, value TEXT, sort INTEGER, surcharge_minor INTEGER, currency TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE variant_option_values (variant_id TEXT, option_id TEXT, option_value_id TEXT, PRIMARY KEY (variant_id, option_id))');
        $pdo->exec('CREATE TABLE variant_quantity_prices (id TEXT PRIMARY KEY, variant_id TEXT, minimum_qty INTEGER, unit_price_minor INTEGER, currency TEXT)');
        $pdo->exec('CREATE TABLE product_components (id TEXT PRIMARY KEY, product_id TEXT, component_variant_id TEXT, qty INTEGER, substitution_policy TEXT)');
        $pdo->exec('CREATE TABLE digital_assets (id TEXT PRIMARY KEY, variant_id TEXT, name TEXT, url TEXT, access_key TEXT, download_limit INTEGER)');

        $seeder = new B2cCatalogSeeder();
        $first = $seeder->seed($pdo);
        $second = $seeder->seed($pdo);

        self::assertSame($first, $second);
        self::assertSame(60, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(29, (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND publish_state = 'published' AND store_id = 'default' AND channel_id = 'web' AND locale = 'en' AND currency = 'USD'")->fetchColumn());
        self::assertSame(29, (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND publish_state = 'published' AND store_id = 'default' AND channel_id = 'web' AND locale = 'en' AND currency = 'VND'")->fetchColumn());
self::assertSame(10, (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND type <> 'simple'")->fetchColumn());
        self::assertSame(240, (int) $pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn());
        self::assertSame(6, (int) $pdo->query("SELECT COUNT(*) FROM product_variants WHERE product_id = 'b2c-prod-01'")->fetchColumn());
        self::assertSame(10, (int) $pdo->query('SELECT COUNT(*) FROM product_components')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM digital_assets')->fetchColumn());
        self::assertSame('configurable', $pdo->query("SELECT type FROM products WHERE id = 'b2c-prod-01'")->fetchColumn());
        self::assertSame('out_of_stock', $pdo->query("SELECT status FROM products WHERE slug = 'natural-latex-mattress' AND currency = 'USD'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT allow_backorder FROM products WHERE slug = 'packable-rope-sun-hat' AND currency = 'USD'")->fetchColumn());
        self::assertSame(4, (int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = 'b2c-prod-27'")->fetchColumn());
        self::assertSame('Linen Mandarin-Collar Shirt', $pdo->query("SELECT name FROM products WHERE id = 'b2c-prod-vnd-01'")->fetchColumn());
        self::assertSame(329000, (int) $pdo->query("SELECT price_minor FROM product_variants WHERE id = 'b2c-prod-vnd-01-mnl'")->fetchColumn());
        self::assertSame(326000, (int) $pdo->query("SELECT price_minor FROM product_variants WHERE id = 'b2c-prod-vnd-01-snl'")->fetchColumn());
        self::assertSame('M', (string) $pdo->query("SELECT value FROM product_option_values WHERE id = 'b2c-prod-01-os-m'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM product_options WHERE product_id = 'b2c-prod-01' AND name = 'Size'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM products WHERE store_id <> 'default' OR channel_id <> 'web' OR locale <> 'en' OR (currency <> 'USD' AND currency <> 'VND')")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM products WHERE tags_json LIKE '%b2b%' OR tags_json LIKE '%marketplace%'")->fetchColumn());
        self::assertSame('Linen Mandarin-Collar Shirt', $pdo->query("SELECT name FROM products WHERE id = 'b2c-prod-01'")->fetchColumn());
        self::assertSame('Apparel', $pdo->query("SELECT name FROM categories WHERE id = 'b2c-cat-thoi-trang'")->fetchColumn());
        self::assertSame('A breathable linen shirt with a clean Mandarin collar for polished warm-weather dressing.', $pdo->query("SELECT description FROM products WHERE id = 'b2c-prod-01'")->fetchColumn());
        self::assertSame('["linen","summer","bestseller"]', $pdo->query("SELECT tags_json FROM products WHERE id = 'b2c-prod-01'")->fetchColumn());
        self::assertSame('Linen Mandarin-Collar Shirt | Default Store', $pdo->query("SELECT meta_title FROM products WHERE id = 'b2c-prod-01'")->fetchColumn());
        self::assertSame(4, (int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = 'b2c-prod-01'")->fetchColumn());
        self::assertSame('/catalog/b2c/linen-mandarin-shirt-alt.svg', $pdo->query("SELECT url FROM product_images WHERE id = 'b2c-prod-01-img-alt'")->fetchColumn());
        self::assertSame('Linen Mandarin-Collar Shirt - front view', $pdo->query("SELECT alt FROM product_images WHERE id = 'b2c-prod-01-img-front'")->fetchColumn());
        self::assertSame('/catalog/b2c/linen-mandarin-shirt-front.svg', $pdo->query("SELECT url FROM product_images WHERE id = 'b2c-prod-01-img-front'")->fetchColumn());
        self::assertSame('/catalog/b2c/linen-mandarin-shirt-detail.svg', $pdo->query("SELECT url FROM product_images WHERE id = 'b2c-prod-01-img-detail'")->fetchColumn());
        self::assertSame('b2c-prod-01', $pdo->query("SELECT id FROM products WHERE slug = 'linen-mandarin-shirt'")->fetchColumn());
    }
}
