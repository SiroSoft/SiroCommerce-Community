<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class MarketplaceLiteContractTest extends TestCase
{
    public function testVendorSurfaceIsPublishedInOpenApiAndSdk(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');

        foreach (['/api/admin/vendors', '/api/admin/vendors/{id}', '/api/vendors/{slug}'] as $path) {
            self::assertArrayHasKey($path, $openapi['paths']);
        }
        foreach (['get_api_admin_vendors', 'post_api_admin_vendors', 'put_api_admin_vendors_id', 'delete_api_admin_vendors_id', 'get_api_vendors_slug'] as $operation) {
            self::assertStringContainsString($operation, $sdk);
        }

        $vendorParam = array_column($openapi['paths']['/api/products']['get']['parameters'], 'name');
        self::assertContains('vendor', $vendorParam);
        self::assertSame('vendor slug; suspended vendors return no products', $openapi['paths']['/api/products']['get']['x-catalog-filters']['vendor']);

        self::assertArrayHasKey('vendor_id', $openapi['components']['schemas']['Request_post_api_admin_products']['properties']);
        self::assertArrayHasKey('vendor_id', $openapi['components']['schemas']['Request_put_api_admin_products_id']['properties']);
        self::assertArrayHasKey('slug', $openapi['components']['schemas']['Request_post_api_admin_vendors']['properties']);
        self::assertSame('^[a-z0-9][a-z0-9-]{0,79}$', $openapi['components']['schemas']['Request_post_api_admin_vendors']['properties']['slug']['pattern']);
        self::assertSame(10000, $openapi['components']['schemas']['Request_post_api_admin_vendors']['properties']['commission_bps']['maximum']);
    }
}
