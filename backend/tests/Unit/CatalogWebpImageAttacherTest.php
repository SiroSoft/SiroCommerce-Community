<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CatalogWebpImageAttacher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CatalogWebpImageAttacherTest extends TestCase
{
    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-webp-images-' . bin2hex(random_bytes(6));
        if (!mkdir($this->publicRoot . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'b2c', 0775, true)) {
            throw new RuntimeException('Could not create temporary public root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->publicRoot);
        parent::tearDown();
    }

    public function testUpdatesSvgUrlsToWebpAndPreservesMetadata(): void
    {
        $pdo = $this->database();
        $this->writeFile('jasmine-oolong-tea-front.webp');
        $this->writeFile('jasmine-oolong-tea-alt.webp');
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-front', 'b2c-prod-22', '/catalog/b2c/jasmine-oolong-tea-front.svg', 'Front view', 0, 'image', 'image/svg+xml', 'gallery')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-alt', 'b2c-prod-22', '/catalog/b2c/jasmine-oolong-tea-alt.svg', 'Alternate view', 1, 'image', 'image/svg+xml', 'gallery')");

        $summary = (new CatalogWebpImageAttacher())->attach($pdo, $this->publicRoot);

        self::assertSame(2, $summary['scanned']);
        self::assertSame(2, $summary['updated']);
        self::assertSame(0, $summary['missing_file']);
        self::assertSame('/catalog/b2c/jasmine-oolong-tea-front.webp', $this->urlFor($pdo, 'img-front'));
        self::assertSame('/catalog/b2c/jasmine-oolong-tea-alt.webp', $this->urlFor($pdo, 'img-alt'));

        $row = $pdo->query("SELECT alt, sort, type, mime_type, role, updated_at FROM product_images WHERE id = 'img-alt'")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('Alternate view', $row['alt']);
        self::assertSame(1, (int) $row['sort']);
        self::assertSame('image', $row['type']);
        self::assertSame('image/webp', $row['mime_type']);
        self::assertSame('gallery', $row['role']);
        self::assertNotNull($row['updated_at']);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $pdo = $this->database();
        $this->writeFile('dried-lotus-seed-tea-front.webp');
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-front', 'b2c-prod-24', '/catalog/b2c/dried-lotus-seed-tea-front.svg', 'Front', 0, 'image', 'image/svg+xml', 'gallery')");

        $attacher = new CatalogWebpImageAttacher();
        $first = $attacher->attach($pdo, $this->publicRoot);
        $second = $attacher->attach($pdo, $this->publicRoot);

        self::assertSame(1, $first['updated']);
        self::assertSame(0, $second['updated']);
        self::assertSame(1, $second['already_raster']);
        self::assertSame('/catalog/b2c/dried-lotus-seed-tea-front.webp', $this->urlFor($pdo, 'img-front'));
    }

    public function testDryRunReportsWorkWithoutWriting(): void
    {
        $pdo = $this->database();
        $this->writeFile('wildflower-honey-jar-front.webp');
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-front', 'b2c-prod-31', '/catalog/b2c/wildflower-honey-jar-front.svg', 'Front', 0, 'image', 'image/svg+xml', 'gallery')");

        $summary = (new CatalogWebpImageAttacher())->attach($pdo, $this->publicRoot, true);

        self::assertTrue($summary['dry_run']);
        self::assertSame(1, $summary['would_update']);
        self::assertSame(0, $summary['updated']);
        self::assertSame('/catalog/b2c/wildflower-honey-jar-front.svg', $this->urlFor($pdo, 'img-front'));
        self::assertSame('image/svg+xml', (string) $pdo->query("SELECT mime_type FROM product_images WHERE id = 'img-front'")->fetchColumn());
    }

    public function testMissingWebpIsSkippedUnlessForced(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-front', 'b2c-prod-30', '/catalog/b2c/gift-wrapping-service-front.svg', 'Front', 0, 'image', 'image/svg+xml', 'gallery')");

        $attacher = new CatalogWebpImageAttacher();
        $skipped = $attacher->attach($pdo, $this->publicRoot);
        self::assertSame(1, $skipped['missing_file']);
        self::assertSame(0, $skipped['updated']);
        self::assertSame('/catalog/b2c/gift-wrapping-service-front.svg', $this->urlFor($pdo, 'img-front'));

        $forced = $attacher->attach($pdo, $this->publicRoot, false, false);
        self::assertSame(1, $forced['updated']);
        self::assertSame('/catalog/b2c/gift-wrapping-service-front.webp', $this->urlFor($pdo, 'img-front'));
    }

    public function testAlreadyRasterAndUnknownUrlsAreUnchanged(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-webp', 'b2c-prod-40', '/catalog/b2c/already-here-front.webp', 'Front', 0, 'image', 'image/webp', 'gallery')");
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-pdf', 'b2c-prod-40', '/catalog/b2c/spec-sheet.pdf', 'Spec', 1, 'file', 'application/pdf', 'gallery')");

        $summary = (new CatalogWebpImageAttacher())->attach($pdo, $this->publicRoot);

        self::assertSame(1, $summary['already_raster']);
        self::assertSame(1, $summary['skipped']);
        self::assertSame(0, $summary['updated']);
        self::assertSame('/catalog/b2c/already-here-front.webp', $this->urlFor($pdo, 'img-webp'));
        self::assertSame('/catalog/b2c/spec-sheet.pdf', $this->urlFor($pdo, 'img-pdf'));
    }

    public function testUnsafeImageUrlIsRejected(): void
    {
        $pdo = $this->database();
        $this->writeFile('jasmine-oolong-tea-front.webp');
        $pdo->exec("INSERT INTO product_images (id, product_id, url, alt, sort, type, mime_type, role) VALUES ('img-front', 'b2c-prod-22', '/catalog/b2c/../jasmine-oolong-tea-front.svg', 'Front', 0, 'image', 'image/svg+xml', 'gallery')");

        $summary = (new CatalogWebpImageAttacher())->attach($pdo, $this->publicRoot);

        self::assertSame(1, $summary['unsafe_url']);
        self::assertSame(0, $summary['updated']);
        self::assertSame('/catalog/b2c/../jasmine-oolong-tea-front.svg', $this->urlFor($pdo, 'img-front'));
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE product_images (id TEXT PRIMARY KEY, product_id TEXT, url TEXT, alt TEXT, sort INTEGER, type TEXT, mime_type TEXT, role TEXT, created_at TEXT, updated_at TEXT)');
        return $pdo;
    }

    private function urlFor(PDO $pdo, string $id): string
    {
        $statement = $pdo->prepare('SELECT url FROM product_images WHERE id = ?');
        $statement->execute([$id]);
        return (string) $statement->fetchColumn();
    }

    private function writeFile(string $name): void
    {
        file_put_contents(
            $this->publicRoot . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'b2c' . DIRECTORY_SEPARATOR . $name,
            'RIFF'
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
