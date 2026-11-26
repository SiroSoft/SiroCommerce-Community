<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class RemainingOperationsTest extends TestCase
{
    public function testGeneratedContractDeclaresSchemasAndIdempotency(): void
    {
        $openapi = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['/api/admin/orders/{id}/cancel', '/api/orders/recover', '/api/admin/payments/reconcile'] as $path) {
            self::assertArrayHasKey('x-request-schema', $openapi['paths'][$path]['post']);
            self::assertArrayHasKey('x-idempotency', $openapi['paths'][$path]['post']);
        }
        self::assertTrue($openapi['paths']['/api/admin/orders/{id}/cancel']['post']['x-idempotency']['required']);
    }
}
