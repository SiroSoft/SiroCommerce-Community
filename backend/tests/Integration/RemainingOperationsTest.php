<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\OrderRepository;
use Tests\TestCase;

final class RemainingOperationsTest extends TestCase
{
    public function testGuestRecoveryRotatesTheExistingAccessRow(): void
    {
        $orders = new OrderRepository();
        $orders->createOrder(['id' => 'recover-order', 'number' => 'REC-1', 'guest_email' => 'guest@example.test', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'pending', 'idempotency_key' => 'recover-order-key']);
        $first = $orders->recoverGuestAccess('REC-1', 'guest@example.test');
        $second = $orders->recoverGuestAccess('REC-1', 'guest@example.test');
        self::assertNotSame($first['token'], $second['token']);
        self::assertFalse($orders->guestTokenIsValid('recover-order', $first['token']));
        self::assertTrue($orders->guestTokenIsValid('recover-order', $second['token']));
    }
}
