<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Category;
use App\Models\ProductVariant;
use App\Modules\Catalog\Application\ProductCsvExportService;
use App\Modules\Catalog\Application\ProductCsvImportService;
use App\Modules\Inventory\Application\InventoryAdminService;
use App\Modules\Platform\Infrastructure\InventoryAdminAdapter;
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

final class ProductCsvTransferTest extends TestCase
{
    private CommerceScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new CommerceScope('default', 'default', 'en', 'USD');
    }

    private function importer(): ProductCsvImportService
    {
        return new ProductCsvImportService(
            new ProductRepository(),
            new InventoryAdminService(new InventoryAdminAdapter(new InventoryRepository())),
        );
    }

    private function exporter(): ProductCsvExportService
    {
        return new ProductCsvExportService(new ProductRepository());
    }

    private function header(): string
    {
        return "sku,name,slug,status,price_minor,compare_price_minor,stock,description,category,min_tier,allow_backorder,is_preorder\n";
    }

    public function testExportReturnsBomHeaderAndCatalogData(): void
    {
        Category::create(['id' => 'cat-export', 'name' => 'Apparel', 'slug' => 'apparel', 'sort' => 1]);
        $csv = $this->header() . "SKU-1,\"Tee, Blue\",tee-blue,active,1500,2000,10,\"Warm, soft\",apparel,gold,1,0\n";
        $this->importer()->import($csv, false, $this->scope, 'owner@example.test', 'key-export-1');

        $export = $this->exporter()->export($this->scope);

        self::assertSame(1, $export['rows']);
        self::assertSame(1, $export['total']);
        self::assertFalse($export['truncated']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $export['csv']);
        $lines = preg_split("/\r\n/", preg_replace('/^\xEF\xBB\xBF/', '', $export['csv']) ?? '');
        self::assertIsArray($lines);
        self::assertSame('sku,name,slug,status,price_minor,compare_price_minor,stock,description,category,min_tier,allow_backorder,is_preorder', $lines[0]);
        $row = str_getcsv($lines[1], ',', '"', '\\');
        self::assertSame(['SKU-1', 'Tee, Blue', 'tee-blue', 'active', '1500', '2000', '10', 'Warm, soft', 'apparel', 'gold', '1', '0'], $row);
    }

    public function testDryRunValidatesWithoutWritingAnything(): void
    {
        $csv = $this->header() . "SKU-DRY,Dry run product,dry-run-product,draft,100,,5,,,,,\n";

        $result = $this->importer()->import($csv, true, $this->scope, 'owner@example.test', 'key-dry-1');

        self::assertSame(1, $result['created']);
        self::assertSame(0, $result['updated']);
        self::assertSame([], $result['errors']);
        self::assertTrue($result['dry_run']);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM product_variants')->fetchColumn());
    }

    public function testCreateUpdateAndIdempotentReplayBySku(): void
    {
        $create = $this->header() . "SKU-UP,Original Name,original-name,draft,1000,,5,Desc,,,0,0\n";
        $first = $this->importer()->import($create, false, $this->scope, 'owner@example.test', 'key-create-1');
        self::assertSame(1, $first['created']);

        $replay = $this->importer()->import($create, false, $this->scope, 'owner@example.test', 'key-create-1');
        self::assertSame(0, $replay['created']);
        self::assertSame(0, $replay['updated']);
        self::assertSame(1, $replay['skipped']);

        $update = $this->header() . "SKU-UP,Updated Name,original-name,active,1200,1500,9,New desc,,silver,1,1\n";
        $second = $this->importer()->import($update, false, $this->scope, 'owner@example.test', 'key-update-1');
        self::assertSame(0, $second['created']);
        self::assertSame(1, $second['updated']);

        $variant = (new ProductRepository())->findVariantBySku('SKU-UP');
        self::assertNotNull($variant);
        self::assertSame(1200, (int) $variant['price_minor']);
        self::assertSame(1500, (int) $variant['compare_price_minor']);
        $product = (new ProductRepository())->findInScope((string) $variant['product_id'], $this->scope);
        self::assertNotNull($product);
        self::assertSame('Updated Name', $product['name']);
        self::assertSame('active', $product['status']);
        self::assertSame('silver', $product['min_tier']);
        self::assertSame(1, (int) $product['allow_backorder']);
        self::assertSame(1, (int) $product['is_preorder']);
        self::assertSame(9, (new ProductRepository())->variantOnHand((string) $variant['id']));
    }

    public function testDirtyCsvWithBomQuotesAndCommasParsesAndBadRowsDoNotAbort(): void
    {
        $csv = "\xEF\xBB\xBF" . $this->header()
            . "SKU-BOM,\"Widget, \"\"Pro\"\"\",widget-pro,active,100,,2,\"Line one\nLine two\",,,,\n"
            . ",Missing SKU,,active,100,,1,,,,,\n"
            . "SKU-BAD,Bad Price,bad-price,active,not-a-number,,1,,,,,\n"
            . "SKU-OK,Good Row,good-row,active,500,,3,,,,,\n";

        $result = $this->importer()->import($csv, false, $this->scope, 'owner@example.test', 'key-dirty-1');

        self::assertSame(2, $result['created']);
        self::assertCount(2, $result['errors']);
        self::assertSame(3, $result['errors'][0]['row']);
        self::assertStringContainsString('sku is required', $result['errors'][0]['error']);
        self::assertSame(4, $result['errors'][1]['row']);
        self::assertStringContainsString('price_minor', $result['errors'][1]['error']);
        $variant = ProductVariant::query()->where('sku', 'SKU-BOM')->first();
        self::assertNotNull($variant);
        $product = (new ProductRepository())->findInScope((string) $variant->toArray()['product_id'], $this->scope);
        self::assertNotNull($product);
        self::assertSame('Widget, "Pro"', $product['name']);
        self::assertSame("Line one\nLine two", $product['description']);
    }

    public function testSizeAndRowLimitsAreRejected(): void
    {
        $tooLarge = str_repeat('a', ProductCsvImportService::MAX_BYTES + 1);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2MB');
        $this->importer()->import($tooLarge, true, $this->scope, 'owner@example.test', 'key-size-1');
    }

    public function testRowLimitIsEnforced(): void
    {
        $csv = $this->header();
        for ($i = 1; $i <= ProductCsvImportService::MAX_ROWS + 1; $i++) {
            $csv .= 'SKU-' . $i . ',Product ' . $i . ',product-' . $i . ",draft,100,,0,,,,\n";
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('500 row limit');
        $this->importer()->import($csv, true, $this->scope, 'owner@example.test', 'key-rows-1');
    }
}
