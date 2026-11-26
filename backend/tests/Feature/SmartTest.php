<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Order\Application\OrderManager;
use App\Modules\Platform\Infrastructure\OrderCommandAdapter;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Smart Commerce (spec 06): action center, rescue, search+commands,
 * inventory insights, setup guide, conversion watch — no LLM.
 */
final class SmartTest extends TestCase
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

        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-sm-1', 'product_id' => 'p-sm-1', 'sku' => 'SM-001', 'price_minor' => 100000]);
        (new InventoryRepository())->setOnHand('v-sm-1', 2, 'test');
        $products = new \App\Repositories\ProductRepository();
        $products->store(['id' => 'p-sm-1', 'name' => 'Smart Widget', 'slug' => 'smart-widget', 'status' => 'active', 'store_id' => 'default', 'channel_id' => 'default', 'locale' => 'en', 'currency' => 'VND']);
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $queryParams = [];
        $parts = explode('?', $path, 2);
        $cleanPath = $parts[0];
        if (isset($parts[1])) {
            parse_str($parts[1], $queryParams);
        }
        $request = new Request($method, $cleanPath, $queryParams, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        /** @var array{status: int, json: mixed} $out */
        $out = ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
        return $out;
    }

    /** @return array<string, string> */
    private function admin(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'a', 'email' => 'smart@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('smart@shop.test', 'secret123');
        $access = $tokens->access;
        return ['Authorization' => 'Bearer ' . (is_string($access) ? $access : ''), 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testActionCenterAndInsights(): void
    {
        $admin = $this->admin();

        $center = $this->call('GET', '/api/admin/smart/action-center', [], $admin);
        $this->assertSame(200, $center['status']);
        $this->assertArrayHasKey('urgent', $center['json']['data']);
        $this->assertArrayHasKey('important', $center['json']['data']);
        $this->assertArrayHasKey('growth', $center['json']['data']);

        $inventory = $this->call('GET', '/api/admin/smart/inventory', [], $admin);
        $this->assertSame(200, $inventory['status']);
        $this->assertCount(1, $inventory['json']['data']);
        $this->assertSame('v-sm-1', $inventory['json']['data'][0]['variant_id']);

        $guide = $this->call('GET', '/api/admin/smart/setup-guide', [], $admin);
        $this->assertSame(200, $guide['status']);

        $conversion = $this->call('GET', '/api/admin/smart/conversion', [], $admin);
        $this->assertSame(200, $conversion['status']);
    }

    public function testRescueFlow(): void
    {
        $admin = $this->admin();
        $orders = new OrderRepository();

        // Healthy order → rescue is a no-op with audit trail.
        $cart = $this->call('POST', '/api/carts', []);
        $cartId = $cart['json']['data']['id'];
        $cartHeaders = ['X-Cart-Token' => $cart['json']['data']['access_token']];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-sm-1', 'qty' => 1], $cartHeaders);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], ['Idempotency-Key' => 'sm-key-1', 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'] + $cartHeaders);
        $orderId = $placed['json']['data']['id'];
        $noop = $this->call('POST', "/api/admin/smart/rescue/{$orderId}", [], $admin);
        $this->assertSame(200, $noop['status']);
        $this->assertFalse($noop['json']['data']['safe']);

        // Failed order with lines → rescue rebuilds a cart.
        $failed = $orders->createOrder([
            'id' => 'ord-failed-1',
            'number' => 'SC-FAILED1',
            'customer_id' => null,
            'guest_email' => 'g@test.com',
            'currency' => 'VND',
            'subtotal_minor' => 100000,
            'discount_minor' => 0,
            'shipping_minor' => 0,
            'tax_minor' => 0,
            'grand_total_minor' => 100000,
            'state' => 'failed',
            'idempotency_key' => 'sm-fail-1',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $orders->addLine([
            'id' => 'line-failed-1',
            'order_id' => is_string($failed['id'] ?? null) ? $failed['id'] : '',
            'variant_id' => 'v-sm-1',
            'sku' => 'SM-001',
            'name' => '',
            'qty' => 1,
            'unit_price_minor' => 100000,
        ]);
        $rescue = $this->call('POST', '/api/admin/smart/rescue/ord-failed-1', [], $admin);
        $this->assertSame(200, $rescue['status']);
        $this->assertTrue($rescue['json']['data']['safe']);
        $this->assertTrue($rescue['json']['data']['verified']);
        $this->assertNotNull($rescue['json']['data']['recovered_cart_id']);

        // Missing order → 404.
        $missing = $this->call('POST', '/api/admin/smart/rescue/nope', [], $admin);
        $this->assertSame(404, $missing['status']);
    }

    public function testSearchAndCommands(): void
    {
        $admin = $this->admin();

        $found = $this->call('GET', '/api/admin/smart/search?q=Smart', [], $admin);
        $this->assertSame(200, $found['status']);
        $this->assertCount(1, $found['json']['data']['products']);

        $shipCmd = $this->call('GET', '/api/admin/smart/search?q=ship SC-X', [], $admin);
        $this->assertSame(200, $shipCmd['status']);
        $this->assertNotCount(0, $shipCmd['json']['data']['commands']);

        // stock command sets on-hand (evidence-linked write).
        $exec = $this->call('POST', '/api/admin/smart/execute', ['command' => 'stock v-sm-1 9'], $admin);
        $this->assertSame(200, $exec['status']);
        $this->assertTrue($exec['json']['data']['ok']);

        $item = $this->call('GET', '/api/admin/inventory/v-sm-1', [], $admin + ['X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $this->assertSame(9, $item['json']['data']['on_hand']);

        $unknown = $this->call('POST', '/api/admin/smart/execute', ['command' => 'frobnicate'], $admin);
        $this->assertSame(422, $unknown['status']);

        // ship command walks an order to shipped.
        $cart = $this->call('POST', '/api/carts', []);
        $cartId = $cart['json']['data']['id'];
        $cartHeaders = ['X-Cart-Token' => $cart['json']['data']['access_token']];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-sm-1', 'qty' => 1], $cartHeaders);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], ['Idempotency-Key' => 'sm-key-2', 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'] + $cartHeaders);
        $number = $placed['json']['data']['number'];
        $ship = $this->call('POST', '/api/admin/smart/execute', ['command' => "ship {$number}"], $admin);
        $this->assertSame(200, $ship['status']);
        $service = new OrderManager(new OrderCommandAdapter(new OrderRepository()));
        $order = $service->transition($placed['json']['data']['id'], 'delivered', 'test', 'verify');
        $this->assertSame('delivered', $order['state']);
    }
}
