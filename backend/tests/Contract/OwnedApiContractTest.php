<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\Api\OwnedApiExactOperationTest;
use PHPUnit\Framework\TestCase;

final class OwnedApiContractTest extends TestCase
{
    /** @return array<string, list<string>> */
    private function ownedRoutes(): array
    {
        return [
            'customer' => [
                'GET /api/products/{id}/reviews', 'POST /api/products/{id}/reviews',
                'GET /api/wishlist', 'POST /api/wishlist', 'DELETE /api/wishlist/{product}',
                'GET /api/account/orders', 'GET /api/account/orders/{id}', 'GET /api/account/returns',
                'GET /api/account/returns/{id}', 'GET /api/account/addresses',
                'GET /api/account/notification-preferences', 'PUT /api/account/notification-preferences',
                'GET /api/account/loyalty', 'POST /api/account/loyalty/{order}/earn',
                'POST /api/account/loyalty/redeem', 'POST /api/account/loyalty/expire',
                'GET /api/admin/customers/{id}/addresses', 'POST /api/admin/customers/{id}/addresses',
            ],
            'fulfillment' => [
                'POST /api/admin/fulfillment/allocate', 'POST /api/admin/fulfillment/{id}/ship',
                'POST /api/admin/fulfillment/{id}/deliver', 'POST /api/admin/fulfillment/{id}/cancel',
                'GET /api/admin/fulfillment/{id}/track', 'POST /api/admin/returns',
                'POST /api/admin/returns/{id}/complete', 'POST /api/admin/returns/{id}/approve',
                'POST /api/admin/returns/{id}/inspect', 'POST /api/admin/returns/{id}/approve-refund',
                'POST /api/admin/returns/{id}/reject', 'POST /api/admin/returns/{id}/label',
                'GET /api/v2/downloads/{token}', 'POST /api/v2/shipping/rates', 'POST /api/v2/shipments',
                'GET /api/v2/shipments/{id}/tracking', 'POST /api/v2/shipments/{id}/cancel',
            ],
            'inventory' => [
                'GET /api/admin/inventory/{variant}', 'PUT /api/admin/inventory/{variant}',
                'POST /api/admin/inventory/{variant}/transfer', 'PUT /api/admin/inventory/{variant}/priorities',
                'POST /api/v2/inventory/{variant}/atp',
            ],
            'b2b' => [
                'POST /api/admin/b2b/companies', 'POST /api/admin/b2b/companies/{id}/members',
                'POST /api/admin/b2b/companies/{id}/approval-scopes', 'POST /api/admin/b2b/companies/{id}/credit-account',
                'GET /api/admin/b2b/companies/{id}/credit', 'POST /api/admin/b2b/companies/{id}/contracts',
                'POST /api/admin/b2b/companies/{id}/catalogs', 'POST /api/admin/b2b/companies/{id}/catalogs/{catalog}/items',
                'POST /api/admin/b2b/companies/{id}/negotiated-prices', 'POST /api/v2/b2b/companies/{id}/quotes',
                'POST /api/v2/b2b/orders/{id}/approve', 'POST /api/v2/b2b/orders/{id}/transition',
                'POST /api/v2/b2b/orders/{id}/draft', 'POST /api/v2/b2b/orders/{id}/submit',
                'POST /api/v2/b2b/orders/{id}/approve-company', 'POST /api/v2/b2b/orders/{id}/reject',
                'GET /api/v2/b2b/companies/{id}/credit', 'POST /api/v2/b2b/contracts/{id}/approve',
            ],
        ];
    }

    public function testEveryOwnedOperationHasOpenApiAndSdkEvidence(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');

        foreach ($this->ownedRoutes() as $routes) foreach ($routes as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            self::assertArrayHasKey($path, $openapi['paths'], $operation);
            self::assertArrayHasKey(strtolower($method), $openapi['paths'][$path], $operation);
            self::assertStringContainsString("'{$method}', '{$path}'", $sdk, $operation);
        }
    }

    public function testFulfillmentEvidenceKeysMatchTheExactHttpOperationInventory(): void
    {
        $base = dirname(__DIR__, 2);
        $evidence = json_decode((string) file_get_contents($base . '/tests/api-handoff/evidence/fulfillment.json'), true, 512, JSON_THROW_ON_ERROR);
        $operations = OwnedApiExactOperationTest::operations();

        $operationKeys = array_keys($operations);
        $evidenceKeys = array_keys($evidence);
        sort($operationKeys);
        sort($evidenceKeys);
        self::assertSame($operationKeys, $evidenceKeys);
        foreach ($evidence as $operation => $layers) {
            self::assertSame(['unit', 'integration', 'api_component', 'contract', 'workflow'], array_keys($layers), $operation);
            foreach ($layers as $layer) {
                self::assertContains($layer['status'], ['PASS', 'BLOCKED', 'SKIP'], $operation);
                if ($layer['status'] === 'PASS') self::assertNotEmpty($layer['evidence'], $operation);
            }
        }
    }
}
