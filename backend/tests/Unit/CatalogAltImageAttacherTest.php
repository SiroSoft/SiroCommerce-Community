<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CatalogAltImageAttacher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CatalogAltImageAttacherTest extends TestCase
{
    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-alt-images-' . bin2hex(random_bytes(6));
        if (!mkdir($this->publicRoot . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'b2c', 0775, true)) {
            throw new RuntimeException('Could not create temporary public root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->publicRoot);
        parent::tearDown();
    }

    public function testAttachIsIdempotentAndAddsAltAfterTheHighestSort(): void
    {
        $pdo = $this->database();
        $this->writeImage('jasmine-oolong-tea');
        $pdo->exec("INSERT INTO products (id, name, status) VALUES ('b2c-prod-22', 'Jasmine Oolong Tea', 'active')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('b2c-prod-22-img-front', 'b2c-prod-22', '/catalog/b2c/jasmine-oolong-tea-front.svg', 'Jasmine Oolong Tea - front view', 0, 'image', 'image/svg+xml', 'gallery')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('b2c-prod-22-img-detail', 'b2c-prod-22', '/catalog/b2c/jasmine-oolong-tea-detail.svg', 'Jasmine Oolong Tea - detail view', 2, 'image', 'image/svg+xml', 'gallery')");

        $attacher = new CatalogAltImageAttacher();
        $first = $attacher->attach($pdo, $this->publicRoot);
        $second = $attacher->attach($pdo, $this->publicRoot);

        self::assertSame(1, $first['scanned']);
        self::assertSame(1, $first['inserted']);
        self::assertSame(0, $first['missing_file']);
        self::assertSame(0, $second['inserted']);
        self::assertSame(1, $second['existing']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM product_images WHERE product_id = 'b2c-prod-22' AND url = '/catalog/b2c/jasmine-oolong-tea-alt.svg'")->fetchColumn());

        $row = $pdo->query("SELECT alt, sort, type, mime_type, role FROM product_images WHERE product_id = 'b2c-prod-22' AND url = '/catalog/b2c/jasmine-oolong-tea-alt.svg'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('Jasmine Oolong Tea alternate view', $row['alt']);
        self::assertSame(3, (int) $row['sort']);
        self::assertSame('image', $row['type']);
        self::assertSame('image/svg+xml', $row['mime_type']);
        self::assertSame('gallery', $row['role']);
    }

    public function testDryRunReportsWorkWithoutWritingRows(): void
    {
        $pdo = $this->database();
        $this->writeImage('dried-lotus-seed-tea');
        $pdo->exec("INSERT INTO products (id, name, status) VALUES ('b2c-prod-24', 'Dried Lotus Seed Tea', 'active')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('b2c-prod-24-img-front', 'b2c-prod-24', '/catalog/b2c/dried-lotus-seed-tea-front.svg', 'Front', 0, 'image', 'image/svg+xml', 'gallery')");

        $summary = (new CatalogAltImageAttacher())->attach($pdo, $this->publicRoot, true);

        self::assertTrue($summary['dry_run']);
        self::assertSame(1, $summary['would_insert']);
        self::assertSame(0, $summary['inserted']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn());
    }

    public function testMissingFileIsSkipped(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO products (id, name, status) VALUES ('b2c-prod-30', 'Gift Wrapping Service', 'active')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('b2c-prod-30-img-front', 'b2c-prod-30', '/catalog/b2c/gift-wrapping-service-front.svg', 'Front', 0, 'image', 'image/svg+xml', 'gallery')");

        $summary = (new CatalogAltImageAttacher())->attach($pdo, $this->publicRoot);

        self::assertSame(1, $summary['scanned']);
        self::assertSame(0, $summary['inserted']);
        self::assertSame(1, $summary['missing_file']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn());
    }

    public function testUnsafeImageUrlIsRejected(): void
    {
        $pdo = $this->database();
        $this->writeImage('jasmine-oolong-tea');
        $pdo->exec("INSERT INTO products (id, name, status) VALUES ('b2c-prod-22', 'Jasmine Oolong Tea', 'active')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('b2c-prod-22-img-front', 'b2c-prod-22', '/catalog/b2c/../jasmine-oolong-tea-front.svg', 'Front', 0, 'image', 'image/svg+xml', 'gallery')");

        $summary = (new CatalogAltImageAttacher())->attach($pdo, $this->publicRoot);

        self::assertSame(0, $summary['inserted']);
        self::assertSame(1, $summary['unsafe_url']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn());
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE products (id TEXT PRIMARY KEY, name TEXT, status TEXT)');
        $pdo->exec('CREATE TABLE product_images (id TEXT PRIMARY KEY, product_id TEXT, url TEXT, alt TEXT, sort INTEGER, type TEXT, mime_type TEXT, role TEXT, created_at TEXT, updated_at TEXT)');
        return $pdo;
    }

    private function writeImage(string $slug): void
    {
        file_put_contents(
            $this->publicRoot . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'b2c' . DIRECTORY_SEPARATOR . $slug . '-alt.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 1100"></svg>'
        );
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
