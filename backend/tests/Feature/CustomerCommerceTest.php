<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customer\Application\CustomerService;
use App\Modules\Platform\Infrastructure\CustomerStoreAdapter;
use App\Repositories\OrderRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class CustomerCommerceTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $stub = new \stdClass();
        $stub->router = $this->router;
        $app = $stub;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    public function testAccountHistoryIsOwnerScopedPaginatedAndPIISafe(): void
    {
        $email = 'account@example.test';
        $auth = $this->call('POST', '/api/auth/register', ['name' => 'Account', 'email' => $email, 'password' => 'secret123']);
        self::assertSame(201, $auth['status']);
        $login = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'secret123']);
        $token = (string) ($login['json']['data']['access'] ?? '');
        // Registration provisions the customer profile, so the account
        // endpoints resolve it without a second explicit registration.
        $customer = (new CustomerStoreAdapter())->findByEmail($email);
        self::assertNotNull($customer);
        $orders = new OrderRepository();
        for ($i = 1; $i <= 3; $i++) {
            $orders->createOrder(['id' => str_pad('ACCOUNT' . $i, 26, '0'), 'number' => 'ACCOUNT-' . $i, 'customer_id' => $customer['id'], 'currency' => 'VND', 'subtotal_minor' => 100, 'discount_minor' => 0, 'shipping_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 100, 'state' => 'delivered', 'idempotency_key' => 'account-' . $i, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $response = $this->call('GET', '/api/account/orders?page=2&per_page=2', [], ['Authorization' => 'Bearer ' . $token]);
        self::assertSame(200, $response['status']);
        self::assertSame(2, $response['json']['meta']['page']);
        self::assertSame(3, $response['json']['meta']['total']);
        self::assertArrayNotHasKey('guest_email', $response['json']['data'][0]);

        $other = $orders->createOrder(['id' => str_pad('OTHER', 26, '0'), 'number' => 'OTHER-1', 'customer_id' => '01JOTHERCUSTOMER0000000001', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'pending', 'idempotency_key' => 'other-1']);
        self::assertSame(404, $this->call('GET', '/api/account/orders/' . $other['id'], [], ['Authorization' => 'Bearer ' . $token])['status']);
    }

    public function testMemberPlacedOrdersAreVisibleInAccountHistory(): void
    {
        $email = 'member-history@example.test';
        $auth = $this->call('POST', '/api/auth/register', ['name' => 'Member', 'email' => $email, 'password' => 'secret123']);
        self::assertSame(201, $auth['status']);
        $login = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'secret123']);
        $token = (string) ($login['json']['data']['access'] ?? '');
        $headers = ['Authorization' => 'Bearer ' . $token];

        $carts = new \App\Repositories\CartRepository();
        $carts->createVariant(['id' => 'v-hist-1', 'product_id' => 'p-hist-1', 'sku' => 'HIST-001', 'price_minor' => 15000]);
        (new \App\Repositories\InventoryRepository())->setOnHand('v-hist-1', 10, 'test');
        $cart = $this->call('POST', '/api/carts', []);
        $cartId = (string) $cart['json']['data']['id'];
        $cartToken = (string) $cart['json']['data']['access_token'];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-hist-1', 'qty' => 1], ['X-Cart-Token' => $cartToken]);
        // Member checkout stamps the auth user id (member attribution), not
        // the customer-profile id — history must resolve both identities.
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], ['Idempotency-Key' => 'hist-1', 'X-Cart-Token' => $cartToken, 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'] + $headers);
        self::assertSame(201, $placed['status']);
        $orderId = (string) $placed['json']['data']['id'];

        $history = $this->call('GET', '/api/account/orders', [], $headers);
        self::assertSame(200, $history['status']);
        $ids = array_map(static fn ($row): string => (string) ($row['id'] ?? ''), $history['json']['data']);
        self::assertContains($orderId, $ids);

        $detail = $this->call('GET', '/api/account/orders/' . $orderId, [], $headers);
        self::assertSame(200, $detail['status']);

        // Another account still cannot see it (PII boundary intact).
        $other = $this->call('POST', '/api/auth/register', ['name' => 'Other', 'email' => 'other-history@example.test', 'password' => 'secret123']);
        self::assertSame(201, $other['status']);
        $otherLogin = $this->call('POST', '/api/auth/login', ['email' => 'other-history@example.test', 'password' => 'secret123']);
        $otherToken = (string) ($otherLogin['json']['data']['access'] ?? '');
        self::assertSame(404, $this->call('GET', '/api/account/orders/' . $orderId, [], ['Authorization' => 'Bearer ' . $otherToken])['status']);
    }

    public function testGuestOrderRequiresItsToken(): void
    {
        $order = (new OrderRepository())->createOrder(['id' => str_pad('GUEST', 26, '0'), 'number' => 'GUEST-1', 'guest_email' => 'private@example.test', 'guest_access_token' => 'guest-secret', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'pending', 'idempotency_key' => 'guest-feature-1']);
        self::assertSame(403, $this->call('GET', '/api/orders/' . $order['id'])['status']);
        $response = $this->call('GET', '/api/orders/' . $order['id'], [], ['X-Order-Token' => 'guest-secret']);
        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('guest_email', $response['json']['data']);
    }

    public function testGuestHandoffRejectsAChannelMismatch(): void
    {
        $order = (new OrderRepository())->createOrder(['id' => str_pad('SCOPED-GUEST', 26, '0'), 'number' => 'SCOPED-1', 'guest_email' => 'private@example.test', 'guest_access_token' => 'scoped-guest-secret', 'currency' => 'VND', 'store_id' => 'store-a', 'channel_id' => 'mobile', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'pending', 'idempotency_key' => 'scoped-guest-1']);
        $mismatch = $this->call('GET', '/api/orders/' . $order['id'], [], ['X-Order-Token' => 'scoped-guest-secret', 'X-Store-Id' => 'store-a', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        self::assertSame(404, $mismatch['status']);
        $valid = $this->call('GET', '/api/orders/' . $order['id'], [], ['X-Order-Token' => 'scoped-guest-secret', 'X-Store-Id' => 'store-a', 'X-Channel-Id' => 'mobile', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        self::assertSame(200, $valid['status']);
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        $route = $path;
        if (str_contains($path, '?')) {
            [$route, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }
        $request = new Request($method, $route, $query, $headers, $body, '127.0.0.1');
        try { $response = $this->router->dispatch($request); } catch (ValidationException $e) { $response = $e->toResponse(); }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }
}
