<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ProductVoucherCodeContractTest extends TestCase
{
    public function testVoucherCodeReadDeclaresTheExactNonProviderContract(): void
    {
        $openapi = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $operation = $openapi['paths']['/api/admin/products/{id}/voucher-codes']['get'];
        self::assertSame('get_api_admin_products_id_voucher_codes', $operation['operationId']);
        self::assertSame('bearer', $operation['x-authentication']);
        self::assertSame('not_applicable', $operation['x-request-body']);
        self::assertFalse($operation['x-idempotency']['required']);
        self::assertArrayHasKey('x-scope', $operation);
        self::assertSame('#/components/schemas/SuccessEnvelope', $operation['x-response-envelope']);
    }
}
