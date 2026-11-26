<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Be1CatalogContractTest extends TestCase
{
    public function testCatalogContractDefinesSupportedProductTypesAndFacets(): void
    {
        $openapi = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['simple', 'configurable', 'grouped', 'bundle', 'virtual', 'downloadable', 'voucher'], $openapi['components']['schemas']['ProductType']['properties']['type']['enum']);
        self::assertArrayHasKey('facets', $openapi['components']['schemas']['PaginationMeta']['properties']);
        self::assertSame('minor units inclusive', $openapi['paths']['/api/products']['get']['x-catalog-filters']['min_price']);
        self::assertArrayHasKey('Money', $openapi['components']['schemas']);
    }

    public function testVoucherHasAProviderNeutralDomainModel(): void
    {
        $openapi = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('voucher', $openapi['components']['schemas']['ProductType']['properties']['type']['enum']);
        self::assertArrayHasKey('/api/admin/products/{id}/voucher-codes', $openapi['paths']);
    }
}
