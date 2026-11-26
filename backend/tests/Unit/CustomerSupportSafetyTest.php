<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Resources\CustomerReturnResource;
use App\Resources\OrderResource;
use PHPUnit\Framework\TestCase;

final class CustomerSupportSafetyTest extends TestCase
{
    public function testCustomerReturnResourceDoesNotExposeProviderDiagnostics(): void
    {
        $result = CustomerReturnResource::make(['id' => 'return-1', 'state' => 'inspected', 'refund_state' => 'failed', 'refund_error' => 'provider token=secret']);

        self::assertSame('failed', $result['refund_state']);
        self::assertArrayNotHasKey('refund_error', $result);
    }

    public function testAdminOrderResourceRedactsContactAndProviderIdentifiers(): void
    {
        $result = OrderResource::make([
            'id' => 'order-1', 'guest_email' => 'private@example.test', 'customer_email' => 'private@example.test',
            'customer_ref' => 'abc123', 'payments' => [['id' => 'payment-1', 'provider_ref' => 'secret-token', 'state' => 'captured']],
        ]);

        self::assertSame('abc123', $result['customer_ref']);
        self::assertArrayNotHasKey('guest_email', $result);
        self::assertArrayNotHasKey('customer_email', $result);
        self::assertArrayNotHasKey('provider_ref', $result['payments'][0]);
    }
}
