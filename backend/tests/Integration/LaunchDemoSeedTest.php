<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Launch\Application\DemoSeedService;
use App\Modules\Launch\Domain\LaunchSeedRefused;
use Siro\Core\Database;
use Tests\TestCase;

final class LaunchDemoSeedTest extends TestCase
{
    public function testSeedCreatesLaunchDataAndIsIdempotent(): void
    {
        $service = new DemoSeedService();
        $first = $service->seed();

        self::assertSame(3, $first['created']['theme']);
        self::assertSame(3, $first['created']['categories']);
        self::assertSame(6, $first['created']['products']);
        self::assertSame(6, $first['created']['variants']);
        self::assertSame(6, $first['created']['inventory']);
        self::assertSame(6, $first['created']['prices']);
        self::assertSame(5, $first['created']['pages']);
        self::assertSame(1, $first['created']['menus']);
        self::assertSame(4, $first['created']['menu_items']);
        self::assertSame(0, array_sum($first['skipped']));

        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM categories WHERE slug LIKE 'demo-%'"));
        self::assertSame(6, $this->countRows("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'"));
        self::assertSame(5, $this->countRows("SELECT COUNT(*) FROM cms_pages WHERE slug IN ('shipping','returns','contact','privacy','cookie-policy') AND status = 'published'"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM cms_menus WHERE key = 'primary' AND status = 'published'"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM storefront_configs WHERE status = 'published' AND version >= 1"));

        $second = $service->seed();
        self::assertSame(0, array_sum($second['created']));
        self::assertSame($first['created'], $second['skipped']);
        self::assertSame(6, $this->countRows("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'"));
    }

    public function testSeedPreservesExistingOperatorData(): void
    {
        Database::execute(
            'INSERT INTO products (id, name, slug, status) VALUES (?,?,?,?)',
            ['real-1', 'Real product', 'real-product', 'active'],
        );

        (new DemoSeedService())->seed();

        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM products WHERE slug = 'real-product' AND status = 'active'"));
        self::assertSame(6, $this->countRows("SELECT COUNT(*) FROM products WHERE slug LIKE 'demo-%'"));
    }

    public function testSeedRefusesEstablishedCatalog(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            Database::execute(
                'INSERT INTO products (id, name, slug, status) VALUES (?,?,?,?)',
                ['real-' . $index, 'Real ' . $index, 'real-' . $index, 'active'],
            );
        }

        $this->expectException(LaunchSeedRefused::class);
        $this->expectExceptionMessage('51 active products');
        (new DemoSeedService())->seed();
    }

    private function countRows(string $sql): int
    {
        $rows = Database::select($sql);
        $first = $rows[0] ?? [];
        $value = is_array($first) ? (array_values($first)[0] ?? 0) : 0;
        return is_numeric($value) ? (int) $value : 0;
    }
}
