<?php
declare(strict_types=1);
namespace Tests\Database;
use Tests\TestCase;
use Siro\Core\Database;

final class MigrationTest extends TestCase
{
    public function testMigrationsCountAndErpTables(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        $this->assertGreaterThanOrEqual(100, count($files), '105 migrations expected');
        // erp tables from latest migration
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('erp_webhook_idempotency', $tables);
        $this->assertContains('booking_slots', $tables);
        $this->assertContains('payments', $tables);
    }
    public function testBookingSlotsSchema(): void
    {
        $cols = Database::connection()->query("PRAGMA table_info('booking_slots')")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $this->assertContains('variant_id', $names);
        $this->assertContains('capacity', $names);
    }

    public function testEnglishSlugMigrationRenamesAndRestores(): void
    {
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO categories (id, name, slug) VALUES ('b2c-cat-my-pham', 'Personal Care', 'cham-soc-ca-nhan')");
        $pdo->exec("INSERT INTO products (id, name, slug, status) VALUES ('b2c-prod-99', 'Shea Repair Hand Cream', 'kem-tay-am-shea', 'active')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url) VALUES ('b2c-prod-99-img-front', 'b2c-prod-99', '/catalog/b2c/kem-tay-am-shea-front.svg')");
        $migration = require dirname(__DIR__, 2) . '/database/migrations/20261007000005_english_slugs.php';
        $migration->up();
        self::assertSame('personal-care', $pdo->query("SELECT slug FROM categories WHERE id = 'b2c-cat-my-pham'")->fetchColumn());
        self::assertSame('shea-repair-hand-cream', $pdo->query("SELECT slug FROM products WHERE id = 'b2c-prod-99'")->fetchColumn());
        self::assertSame('/catalog/b2c/shea-repair-hand-cream-front.svg', $pdo->query("SELECT url FROM product_images WHERE id = 'b2c-prod-99-img-front'")->fetchColumn());
        $migration->down();
        self::assertSame('cham-soc-ca-nhan', $pdo->query("SELECT slug FROM categories WHERE id = 'b2c-cat-my-pham'")->fetchColumn());
        self::assertSame('kem-tay-am-shea', $pdo->query("SELECT slug FROM products WHERE id = 'b2c-prod-99'")->fetchColumn());
    }

    public function testNoTextJsonBlobColumnCarriesADefault(): void
    {
        // MySQL 8 rejects defaults on TEXT/BLOB/JSON (error 1101) while
        // sqlite silently accepts them — so a static scan is the only gate
        // that catches 20261007000006-class breakage before deploy.
        $offenders = [];
        foreach ((array) glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/->(?:text|json|blob)\s*\([^)]*\)\s*->default\s*\(/', $source) === 1) {
                $offenders[] = basename($file);
            }
        }
        self::assertSame([], $offenders, 'TEXT/JSON/BLOB defaults are illegal on MySQL 8');
    }
}
