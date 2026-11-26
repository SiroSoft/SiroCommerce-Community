<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Resources\CustomerResource;
use PHPUnit\Framework\TestCase;

final class CustomerResourceTest extends TestCase
{
    public function testCustomerReadDoesNotExposeAuthenticationOrInternalFields(): void
    {
        $data = CustomerResource::make([
            'id' => 'customer-1',
            'name' => 'Test Customer',
            'email' => 'customer@example.test',
            'password_hash' => 'secret',
            'reset_token' => 'token',
            'internal_notes' => 'private',
        ]);

        self::assertSame('customer-1', $data['id']);
        self::assertArrayNotHasKey('password_hash', $data);
        self::assertArrayNotHasKey('reset_token', $data);
        self::assertArrayNotHasKey('internal_notes', $data);
    }
}
