<?php

declare(strict_types=1);

namespace Tests\Workflow;

use PHPUnit\Framework\TestCase;

final class ApiWorkflowContractTest extends TestCase
{
    public function testCheckoutWorkflowContractKeepsTheRequiredReplayKeyAcrossTheSequence(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($base . '/routes/api.php');
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString("'/api/carts'", $routes);
        self::assertStringContainsString("'/api/carts/{id}/lines'", $routes);
        self::assertStringContainsString("'/api/checkout/place'", $routes);
        self::assertStringContainsString("'/api/storefront/context'", $routes);
        self::assertTrue($openapi['paths']['/api/checkout/place']['post']['x-idempotency']['required']);
        self::assertSame('v1', $openapi['paths']['/api/checkout/place']['post']['x-api-version']);
    }

    public function testGeneratedContractDoesNotInventBodiesOrPagination(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('not_applicable', $openapi['paths']['/api/admin/fulfillment/{id}/deliver']['post']['x-request-body']);
        self::assertArrayNotHasKey('requestBody', $openapi['paths']['/api/admin/fulfillment/{id}/deliver']['post']);
        self::assertSame('#/components/schemas/PaginatedEnvelope', $openapi['paths']['/api/account/orders']['get']['x-response-envelope']);
        self::assertArrayNotHasKey('page', array_column($openapi['paths']['/api/products/{slug}']['get']['parameters'], 'name'));
        self::assertContains('VALIDATION_ERROR', $openapi['paths']['/api/checkout/place']['post']['x-stable-error-codes']);
    }

    public function testNonProviderWritesHaveConcreteRequestAndResponseContracts(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $unknown = [];
        foreach ($openapi['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || str_contains($path, '/webhooks/')) continue;
                if (($operation['x-request-body'] ?? null) === 'unknown') $unknown[] = strtoupper($method) . ' ' . $path;
                self::assertNotSame('#/components/schemas/SuccessEnvelope', $operation['x-response-envelope'], strtoupper($method) . ' ' . $path);
                if (($operation['x-request-body'] ?? null) === 'application/json') {
                    self::assertArrayHasKey('requestBody', $operation);
                    self::assertArrayHasKey('schema', $operation['requestBody']['content']['application/json']);
                }
            }
        }
        self::assertSame([], $unknown);
        self::assertSame('#/components/schemas/ProviderWebhookResultEnvelope', $openapi['paths']['/api/webhooks/{provider}']['post']['x-response-envelope']);
        self::assertArrayHasKey('x-provider-owned-fields', $openapi['paths']['/api/webhooks/{provider}']['post']);
    }

    public function testQueryOnlyPaymentReconciliationDoesNotAdvertiseAJsonBody(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $operation = $openapi['paths']['/api/admin/payments/reconcile']['post'];
        $schema = $openapi['components']['schemas']['Request_post_api_admin_payments_reconcile'];

        self::assertSame('application/json', $operation['x-request-body']);
        self::assertSame('boolean', $schema['properties']['apply']['type']);
        self::assertFalse($operation['requestBody']['required']);
    }

    public function testCatalogTaxonomyReadsHaveStableTypedResponsesAndScopeMetadata(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['product-types' => 'ProductTypeList', 'tags' => 'CatalogTagList'] as $path => $schema) {
            $operation = $openapi['paths']['/api/v2/catalog/' . $path]['get'];
            self::assertSame('#/components/schemas/' . $schema . 'Envelope', $operation['x-response-envelope']);
            self::assertSame('fe_shared_secret', $operation['x-authentication']);
            self::assertArrayHasKey('x-scope', $operation);
        }
    }
}
