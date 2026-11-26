<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\StorefrontConfig\Infrastructure\StorefrontConfigSeeder;
use Siro\Core\Database;
use Tests\TestCase;

final class StorefrontConfigMigrationTest extends TestCase
{
    public function testTablesAndIdempotentDefaultsExist(): void
    {
        $pdo = Database::connection();
        StorefrontConfigSeeder::seed($pdo);
        StorefrontConfigSeeder::seed($pdo);
        foreach (['storefront_themes', 'storefront_vertical_presets', 'storefront_configs', 'storefront_config_revisions'] as $table) {
            self::assertNotFalse($pdo->query('SELECT 1 FROM `' . $table . '`'));
        }
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM storefront_themes WHERE `key`='editorial' AND version='1.0.0'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM storefront_vertical_presets WHERE `key`='general' AND version='1.0.0'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM storefront_configs WHERE store_id='default' AND channel_id='default' AND status='published'")->fetchColumn());
    }
}
