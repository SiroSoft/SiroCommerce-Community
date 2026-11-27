<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Infrastructure\CustomerStoreAdapter;
use App\Repositories\OrderRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class CustomerAccountCoverageTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    public function testAccountCommerceEndpointsCoverEmptyAndValidationStates(): void
    {
        $headers = $this->customerHeaders('customer-coverage@example.test');

        $returns = $this->call('GET', '/api/account/returns', [], $headers);
        self::assertSame(200, $returns['status']);
        self::assertSame([], $returns['json']['data']);

        $addresses = $this->call('GET', '/api/account/addresses', [], $headers);
        self::assertSame(200, $addresses['status']);
        self::assertSame([], $addresses['json']['data']);

        $created = $this->call('POST', '/api/account/addresses', [
            'label' => 'Home', 'line1' => '12 Main', 'city' => 'HCM', 'phone' => '+84 900 123 456',
        ], $headers);
        self::assertSame(201, $created['status']);
        self::assertSame('12 Main', $created['json']['data']['line1']);

        $invalidAddress = $this->call('POST', '/api/account/addresses', ['label' => 'Broken'], $headers);
        self::assertSame(422, $invalidAddress['status']);

        $preferences = $this->call('GET', '/api/account/notification-preferences', [], $headers);
        self::assertSame(200, $preferences['status']);
        self::assertSame(1, $preferences['json']['data']['transactional_email']);

        $updated = $this->call('PUT', '/api/account/notification-preferences', [
            'transactional_email' => false, 'marketing_email' => true,
        ], $headers + ['Idempotency-Key' => 'pref-key-1']);
        self::assertSame(200, $updated['status']);
        self::assertSame(0, $updated['json']['data']['transactional_email']);
        self::assertSame(1, $updated['json']['data']['marketing_email']);

        $invalidPreference = $this->call('PUT', '/api/account/notification-preferences', ['marketing_email' => 'nope'], $headers);
        self::assertSame(422, $invalidPreference['status']);

        $loyalty = $this->call('GET', '/api/account/loyalty', [], $headers);
        self::assertSame(200, $loyalty['status']);
        self::assertSame(0, $loyalty['json']['data']['balance_points']);

        $redeem = $this->call('POST', '/api/account/loyalty/redeem', ['points' => 5], $headers + ['Idempotency-Key' => 'redeem-1']);
        self::assertSame(422, $redeem['status']);
        self::assertStringContainsString('Insufficient points', (string) ($redeem['json']['message'] ?? ''));

        $invalidRedeem = $this->call('POST', '/api/account/loyalty/redeem', ['points' => 0], $headers);
        self::assertSame(422, $invalidRedeem['status']);

        $expire = $this->call('POST', '/api/account/loyalty/expire', ['before' => 'not-a-date'], $headers);
        self::assertSame(422, $expire['status']);

        $expireNothing = $this->call('POST', '/api/account/loyalty/expire', ['before' => date('Y-m-d')], $headers + ['Idempotency-Key' => 'expire-account-1']);
        self::assertSame(422, $expireNothing['status']);
        self::assertStringContainsString('eligible', (string) ($expireNothing['json']['message'] ?? ''));

        $earnMissing = $this->call('POST', '/api/account/loyalty/missing-order/earn', [], $headers + ['Idempotency-Key' => 'earn-1']);
        self::assertSame(422, $earnMissing['status']);
        self::assertStringContainsString('Order not found', (string) ($earnMissing['json']['message'] ?? ''));

        $history = $this->call('GET', '/api/account/message-history', [], $headers);
        self::assertSame(200, $history['status']);
        self::assertSame(0, $history['json']['meta']['total']);
    }

    public function testAccountReturnEndpointsValidateOwnershipAndLineShape(): void
    {
        $email = 'customer-returns@example.test';
        $headers = $this->customerHeaders($email);
        $customer = (new CustomerStoreAdapter())->findByEmail($email);
        self::assertNotNull($customer);
        $order = (new OrderRepository())->createOrder([
            'id' => str_pad('COV-RET', 26, '0'), 'number' => 'COV-RET-1', 'customer_id' => $customer['id'],
            'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000,
            'state' => 'delivered', 'idempotency_key' => 'cov-ret-1', 'store_id' => 'default', 'channel_id' => 'default',
        ]);

        $missing = $this->call('GET', '/api/account/returns/missing-return', [], $headers);
        self::assertSame(404, $missing['status']);

        $notifications = $this->call('GET', '/api/account/orders/' . $order['id'] . '/notifications', [], $headers);
        self::assertSame(200, $notifications['status']);
        self::assertSame([], $notifications['json']['data']);

        $badLines = $this->call('POST', '/api/account/orders/' . $order['id'] . '/returns', [
            'fulfillment_id' => 'fulfillment-1',
            'lines' => [['order_line_id' => 'line-1', 'qty' => 'many']],
        ], $headers + ['Idempotency-Key' => 'ret-bad-lines']);
        self::assertSame(422, $badLines['status']);
        self::assertSame('Return line details are invalid', $badLines['json']['message'] ?? null);

        $nonObjectLine = $this->call('POST', '/api/account/orders/' . $order['id'] . '/returns', [
            'fulfillment_id' => 'fulfillment-1',
            'lines' => ['not-an-object'],
        ], $headers + ['Idempotency-Key' => 'ret-non-object']);
        self::assertSame(422, $nonObjectLine['status']);
        self::assertSame('Return lines must be objects', $nonObjectLine['json']['message'] ?? null);

        $missingFulfillment = $this->call('POST', '/api/account/orders/' . $order['id'] . '/returns', [
            'lines' => [['order_line_id' => 'line-1', 'qty' => 1]],
        ], $headers);
        self::assertSame(422, $missingFulfillment['status']);

        $other = (new OrderRepository())->createOrder([
            'id' => str_pad('COV-OTHER', 26, '0'), 'number' => 'COV-OTHER-1', 'customer_id' => 'other-customer-id',
            'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000,
            'state' => 'delivered', 'idempotency_key' => 'cov-other-1',
        ]);
        $forbidden = $this->call('POST', '/api/account/orders/' . $other['id'] . '/returns', [
            'fulfillment_id' => 'fulfillment-1',
            'lines' => [['order_line_id' => 'line-1', 'qty' => 1]],
        ], $headers + ['Idempotency-Key' => 'ret-forbidden']);
        self::assertSame(404, $forbidden['status']);
    }

    public function testAdminCustomerDetailAddressesAndMissingStates(): void
    {
        $customerEmail = 'customer-admin-view@example.test';
        $this->customerHeaders($customerEmail);
        $customer = (new CustomerStoreAdapter())->findByEmail($customerEmail);
        self::assertNotNull($customer);
        $id = (string) $customer['id'];

        $admin = $this->staffHeaders('customer-admin-view@shop.test', 'admin');
        $detail = $this->call('GET', '/api/admin/customers/' . $id, [], $admin);
        self::assertSame(200, $detail['status']);
        self::assertArrayHasKey('email', $detail['json']['data']);

        $support = $this->staffHeaders('customer-support-view@shop.test', 'support');
        $supportDetail = $this->call('GET', '/api/admin/customers/' . $id, [], $support);
        self::assertSame(200, $supportDetail['status']);
        self::assertArrayNotHasKey('email', $supportDetail['json']['data']);

        $addresses = $this->call('GET', '/api/admin/customers/' . $id . '/addresses', [], $admin);
        self::assertSame(200, $addresses['status']);

        $created = $this->call('POST', '/api/admin/customers/' . $id . '/addresses', [
            'line1' => '9 Admin St', 'city' => 'HN', 'phone' => '0901234567',
        ], $admin);
        self::assertSame(201, $created['status']);

        self::assertSame(404, $this->call('GET', '/api/admin/customers/missing-customer', [], $admin)['status']);
        self::assertSame(404, $this->call('GET', '/api/admin/customers/missing-customer/addresses', [], $admin)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/customers/missing-customer/addresses', [
            'line1' => 'x', 'city' => 'y', 'phone' => '0901234567',
        ], $admin)['status']);
    }

    /** @return array<string, string> */
    private function customerHeaders(string $email): array
    {
        $register = $this->call('POST', '/api/auth/register', ['name' => 'Customer', 'email' => $email, 'password' => 'secret123']);
        self::assertSame(201, $register['status']);
        $login = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'secret123']);
        $token = (string) ($login['json']['data']['access'] ?? '');
        return ['Authorization' => 'Bearer ' . $token, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @return array<string, string> */
    private function staffHeaders(string $email, string $role): array
    {
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter(new \App\Repositories\UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: array<string, mixed>} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        $route = $path;
        if (str_contains($path, '?')) {
            [$route, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }
        $request = new Request($method, $route, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        $decoded = json_decode($output, true);
        /** @var array<string, mixed> $decoded */
        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }
}
