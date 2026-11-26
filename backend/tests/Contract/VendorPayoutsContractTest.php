<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class VendorPayoutsContractTest extends TestCase
{
    public function testVendorPayoutSurfaceIsPublishedInOpenApiAndSdk(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');

        $paths = [
            '/api/admin/vendors/{id}/ledger' => 'get',
            '/api/admin/vendors/{id}/payouts' => 'get',
            '/api/admin/vendors/{id}/payouts' => 'post',
            '/api/admin/vendor-payouts/{id}/mark-paid' => 'post',
            '/api/admin/vendor-payouts/{id}/cancel' => 'post',
            '/api/admin/vendor-payouts/{id}/export.csv' => 'get',
            '/api/account/vendor/ledger' => 'get',
            '/api/account/vendor/payouts' => 'get',
        ];
        foreach ($paths as $path => $method) {
            self::assertArrayHasKey($path, $openapi['paths']);
            self::assertArrayHasKey($method, $openapi['paths'][$path]);
            $operationId = $method . '_' . trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $path), '_');
            self::assertSame($operationId, $openapi['paths'][$path][$method]['operationId']);
            self::assertStringContainsString($operationId, $sdk);
        }

        $create = $openapi['paths']['/api/admin/vendors/{id}/payouts']['post'];
        self::assertSame('application/json', $create['x-request-body']);
        self::assertTrue($create['x-idempotency']['required']);
        $request = $openapi['components']['schemas']['Request_post_api_admin_vendors_id_payouts'];
        self::assertSame(['period_start', 'period_end'], $request['required']);
        self::assertArrayHasKey('period_start', $request['properties']);
        self::assertArrayHasKey('period_end', $request['properties']);
        self::assertSame('not_applicable', $openapi['paths']['/api/admin/vendor-payouts/{id}/mark-paid']['post']['x-request-body']);
        self::assertSame('not_applicable', $openapi['paths']['/api/admin/vendor-payouts/{id}/cancel']['post']['x-request-body']);
        self::assertSame(['payout_id', 'vendor', 'amount_minor', 'currency', 'period_start', 'period_end', 'status'], $openapi['paths']['/api/admin/vendor-payouts/{id}/export.csv']['get']['x-csv-export']['columns']);
        self::assertArrayHasKey('X-Export-Rows', $openapi['paths']['/api/admin/vendor-payouts/{id}/export.csv']['get']['responses']['200']['headers']);
        self::assertArrayHasKey('owner_customer_id', $openapi['components']['schemas']['Request_post_api_admin_vendors']['properties']);
    }
}
