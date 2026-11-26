<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class MarketplaceContractTest extends TestCase
{
    public function testMarketplaceSurfaceHasScopeSafeRoutesAndArtifacts(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($base . '/routes/api.php');
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');
        foreach (['/api/v2/admin/vendors', '/api/v2/admin/vendors/{id}/status', '/api/v2/admin/vendors/{vendor}/catalog', '/api/v2/admin/commissions', '/api/v2/admin/seller-orders', '/api/v2/admin/payouts/{id}/transition'] as $path) {
            self::assertStringContainsString($path, $routes);
            self::assertArrayHasKey($path, $openapi['paths']);
        }
        self::assertStringContainsString('BLOCKED_EXTERNAL', (string) file_get_contents($base . '/app/Modules/Platform/Application/MarketplaceService.php'));
        self::assertStringContainsString('get_api_v2_admin_seller_orders', $sdk);
    }
}
