<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ApiOpenApiSdkContractTest extends TestCase
{
    public function testGeneratedOpenApiAndPublicCopyAreIdenticalAndSdkContainsEveryOperation(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $public = json_decode((string) file_get_contents($base . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');

        self::assertSame($openapi, $public);
        foreach ($openapi['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                self::assertArrayHasKey('x-request-schema', $operation, $method . ' ' . $path);
                self::assertArrayHasKey('x-idempotency', $operation, $method . ' ' . $path);
                self::assertStringContainsString(var_export(strtoupper($method), true) . ', ' . var_export($path, true), $sdk);
            }
        }
    }

    public function testSecurityAndCommerceScopeMetadataMatchSensitiveRoutes(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');

        self::assertSame('webhook_hmac', $openapi['paths']['/api/webhooks/{provider}']['post']['x-authentication']);
        self::assertSame('webhook_hmac', $openapi['paths']['/api/webhooks/shipping/{provider}']['post']['x-authentication']);
        self::assertSame('bearer', $openapi['paths']['/api/admin/products/{id}']['put']['x-authentication']);
        self::assertArrayHasKey('x-scope', $openapi['paths']['/api/admin/products/{id}']['put']);
        self::assertArrayHasKey('x-scope', $openapi['paths']['/api/admin/pages/{id}/preview']['get']);
        self::assertSame('#/components/schemas/ProductTypeListEnvelope', $openapi['paths']['/api/v2/catalog/product-types']['get']['x-response-envelope']);
        self::assertSame('#/components/schemas/CatalogTagListEnvelope', $openapi['paths']['/api/v2/catalog/tags']['get']['x-response-envelope']);
        self::assertArrayHasKey('x-scope', $openapi['paths']['/api/v2/catalog/tags']['get']);
        self::assertStringContainsString("'GET', '/api/v2/catalog/product-types'", $sdk);
        self::assertStringContainsString("'GET', '/api/v2/catalog/tags'", $sdk);
    }

    public function testAdminCancelAndPaymentReconcileBodiesMatchTheirCallers(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $cancel = $openapi['paths']['/api/admin/orders/{id}/cancel']['post'];
        $reconcile = $openapi['paths']['/api/admin/payments/reconcile']['post'];
        $cancelSchema = $openapi['components']['schemas']['Request_post_api_admin_orders_id_cancel'];
        $reconcileSchema = $openapi['components']['schemas']['Request_post_api_admin_payments_reconcile'];

        self::assertSame('application/json', $cancel['x-request-body']);
        self::assertSame(['reason'], array_keys($cancelSchema['properties']));
        self::assertSame('application/json', $reconcile['x-request-body']);
        self::assertSame(['apply'], array_keys($reconcileSchema['properties']));
        self::assertFalse($reconcile['requestBody']['required']);
    }

    public function testHandoffPassEvidenceReferencesExistingFiles(): void
    {
        $base = dirname(__DIR__, 2);
        $matrix = json_decode((string) file_get_contents($base . '/docs/api-handoff/coverage-matrix.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($matrix['routes'] as $key => $route) {
            foreach ($route['layers'] as $layer => $record) {
                if ($record['status'] !== 'PASS') continue;
                foreach (array_filter(array_map('trim', explode(';', (string) $record['evidence']))) as $evidence) {
                    [$reference] = explode('::', $evidence, 2);
                    self::assertFileExists($base . '/' . $reference, $key . ' ' . $layer);
                }
            }
        }
    }
}
