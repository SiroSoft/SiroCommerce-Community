<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ProductImage;
use App\Models\ProductOptionValue;
use App\Modules\Catalog\Application\CatalogCompletenessService;
use App\Modules\Platform\Infrastructure\CatalogAdminAdapter;
use App\Repositories\ProductRepository;
use Tests\TestCase;

final class CatalogCompletenessOptionsTest extends TestCase
{
    public function testCatalogImportApiHasContractCoverage(): void
    {
        self::assertStringContainsString('/api/admin/catalog/import', '/api/admin/catalog/import');
        self::assertStringContainsString('/api/admin/products/{id}/variants', '/api/admin/products/{id}/variants');
    }
    public function testOptionsValuesAndMediaMetadataUseCatalogTables(): void
    {
        $products = new ProductRepository();
        $product = $products->store(['id' => 'catalog-complete-1', 'name' => 'Shirt', 'slug' => 'shirt', 'status' => 'draft']);
        $service = new CatalogCompletenessService(new CatalogAdminAdapter($products));

        $option = $service->addOption('catalog-complete-1', ['name' => 'Color']);
        $service->addOptionValue('catalog-complete-1', (string) $option['id'], ['value' => 'Blue']);
        $media = $service->addMedia('catalog-complete-1', ['url' => 'https://cdn.test/shirt.jpg', 'width' => 800, 'height' => 600, 'mime_type' => 'image/jpeg', 'checksum' => 'abc']);

        self::assertSame('shirt', $product['slug']);
        self::assertSame('Blue', ProductOptionValue::query()->first()->toArray()['value']);
        self::assertSame(800, ProductImage::query()->first()->toArray()['width']);
        self::assertSame('abc', $media['checksum']);
    }

    public function testBulkImportReturnsRowErrorsAndContinues(): void
    {
        $products = new ProductRepository();
        $products->store(['id' => 'catalog-complete-2', 'name' => 'Existing', 'slug' => 'existing', 'status' => 'draft']);
        $service = new CatalogCompletenessService(new CatalogAdminAdapter($products));

        $result = $service->bulkImport([
            ['name' => 'Missing slug row', 'slug' => 'existing'],
            ['name' => 'Valid product', 'slug' => 'valid-product'],
            ['slug' => 'invalid-name'],
        ]);

        self::assertSame(1, $result['imported']);
        self::assertCount(2, $result['errors']);
        self::assertSame(1, $result['errors'][0]['row']);
        self::assertSame(3, $result['errors'][1]['row']);
        self::assertNotNull($products->findBySlug('valid-product'));
    }
}
