<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\GuestOrderAccess;
use App\Modules\Customer\Application\CustomerService;
use App\Modules\Platform\Infrastructure\CustomerStoreAdapter;
use App\Repositories\OrderRepository;
use App\Repositories\CartRepository;
use Tests\TestCase;

final class CustomerSecurityTest extends TestCase
{
    public function testGuestOrderAccessIsHashedExpiringAndRevocable(): void
    {
        $orders = new OrderRepository();
        $token = 'guest-token-for-test';
        $order = $orders->createOrder([
            'id' => '01JTESTGUESTORDER0000000001',
            'number' => 'TEST-GUEST-1',
            'guest_email' => 'guest@example.com',
            'guest_access_token' => $token,
            'currency' => 'VND',
            'subtotal_minor' => 100,
            'discount_minor' => 0,
            'shipping_minor' => 0,
            'tax_minor' => 0,
            'grand_total_minor' => 100,
            'state' => 'pending',
            'idempotency_key' => 'guest-security-1',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::assertSame($token, $order['guest_access_token']);
        self::assertTrue($orders->guestTokenIsValid($order['id'], $token));
        self::assertFalse($orders->guestTokenIsValid($order['id'], 'wrong-token'));
        self::assertArrayNotHasKey('guest_access_token', $orders->find($order['id']));

        $access = GuestOrderAccess::query()->where('order_id', $order['id'])->first();
        self::assertNotNull($access);
        self::assertSame(hash('sha256', $token), $access->toArray()['token_hash']);
        self::assertNotSame($token, $access->toArray()['token_hash']);

        $access->setAttribute('expires_at', date('Y-m-d H:i:s', time() - 1));
        $access->save();
        self::assertFalse($orders->guestTokenIsValid($order['id'], $token));

        $orders->revokeGuestToken($order['id']);
        self::assertFalse($orders->guestTokenIsValid($order['id'], $token));
    }

    public function testCustomerAddressReadIsScopedToAnExistingCustomer(): void
    {
        $customers = new CustomerService(new CustomerStoreAdapter());
        $customer = $customers->register('security@example.com', 'Security Test');
        $address = $customers->addAddress($customer['id'], ['line1' => '1 Main', 'city' => 'Hanoi', 'phone' => '0900000000']);

        self::assertSame($address['id'], $customers->addresses($customer['id'])[0]['id']);
        $this->expectException(\RuntimeException::class);
        $customers->addresses('01JUNKNOWNCUSTOMER000000000');
    }

    public function testCartBearerIsStoredAsDigestAndCannotCrossCartBoundaries(): void
    {
        $repository = new CartRepository();
        $token = str_repeat('a', 64);
        $repository->createCart([
            'id' => '01JTESTCART000000000000001',
            'customer_id' => null,
            'access_token_hash' => hash('sha256', $token),
            'currency' => 'VND',
            'state' => 'open',
        ]);

        self::assertTrue($repository->accessTokenIsValid('01JTESTCART000000000000001', $token));
        self::assertFalse($repository->accessTokenIsValid('01JOTHER_CART00000000000001', $token));
        self::assertNotSame($token, $repository->findCart('01JTESTCART000000000000001')['access_token_hash'] ?? null);
    }
}
