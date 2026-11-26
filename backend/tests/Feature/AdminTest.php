<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\UserRepository;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Models\Order;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Merchant admin (spec 04 + 08): RBAC gates, product CRUD, order transition,
 * inventory adjust, coupons, DemoGuard readonly (UAT-10).
 */
final class AdminTest extends TestCase
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

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
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
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        $access = $tokens->access;
        return ['Authorization' => 'Bearer ' . $access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'USD'];
    }

    public function testRbacGates(): void
    {
        $anon = $this->call('GET', '/api/admin/products');
        $this->assertSame(401, $anon['status']);

        $viewer = $this->loginAs('viewer@shop.test', 'viewer');
        $forbidden = $this->call('GET', '/api/admin/products', [], $viewer);
        $this->assertSame(403, $forbidden['status']);

        $support = $this->loginAs('support@shop.test', 'support');
        $wrongDept = $this->call('GET', '/api/admin/products', [], $support);
        $this->assertSame(403, $wrongDept['status']);

        $admin = $this->loginAs('admin@shop.test', 'admin');
        $ok = $this->call('GET', '/api/admin/products', [], $admin);
        $this->assertSame(200, $ok['status']);
    }

    public function testProductCrudAndInventory(): void
    {
        $admin = $this->loginAs('owner@shop.test', 'owner');

        $created = $this->call('POST', '/api/admin/products', [
            'name' => 'Ao Thun', 'price_minor' => 199000, 'stock' => 50, 'sku' => 'AO-001',
        ], $admin);
        $this->assertSame(201, $created['status']);
        /** @var array<string, mixed> $product */
        $product = $created['json']['data'];
        $this->assertSame('ao-thun', $product['slug']);
        $productId = is_string($product['id'] ?? null) ? $product['id'] : '';
        /** @var array<int, array<string, mixed>> $variants */
        $variants = $product['variants'];
        $variantId = is_string($variants[0]['id'] ?? null) ? $variants[0]['id'] : '';

        $stock = $this->call('GET', "/api/admin/inventory/{$variantId}", [], $admin);
        $this->assertSame(200, $stock['status']);
        $this->assertSame(50, $stock['json']['data']['on_hand']);

        $adjust = $this->call('PUT', "/api/admin/inventory/{$variantId}", ['on_hand' => 80], $admin);
        $this->assertSame(200, $adjust['status']);
        $this->assertSame(80, $adjust['json']['data']['on_hand']);

        $update = $this->call('PUT', "/api/admin/products/{$productId}", ['status' => 'active'], $admin);
        $this->assertSame(200, $update['status']);

        $storefront = $this->call('GET', '/api/products');
        $this->assertSame(200, $storefront['status']);
        $this->assertSame(1, count($storefront['json']['data']));

        $delete = $this->call('DELETE', "/api/admin/products/{$productId}", [], $admin);
        $this->assertSame(204, $delete['status']);
    }

    public function testOrderTransitionAndRefund(): void
    {
        $admin = $this->loginAs('admin2@shop.test', 'admin');
        $carts = new CartRepository();
        $variant = $carts->createVariant(['id' => 'v-adm-1', 'product_id' => 'p-adm-1', 'sku' => 'ADM-001', 'price_minor' => 50000]);
        $variantId = is_string($variant['id'] ?? null) ? $variant['id'] : '';
        (new InventoryRepository())->setOnHand($variantId, 10, 'test');

        $cart = $this->call('POST', '/api/carts', []);
        $cartId = $cart['json']['data']['id'];
        $cartHeaders = ['X-Cart-Token' => $cart['json']['data']['access_token']];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => $variantId, 'qty' => 1], $cartHeaders);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], ['Idempotency-Key' => 'adm-key-1', 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'USD'] + $cartHeaders);
        $orderId = $placed['json']['data']['id'];
        Order::find($orderId)?->update(['store_id' => 'default', 'channel_id' => 'default']);

        $bad = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'delivered'], $admin);
        $this->assertSame(422, $bad['status']);

        // Happy path: awaiting_payment → paid → processing → ready_to_ship.
        foreach (['paid', 'processing', 'ready_to_ship'] as $to) {
            $step = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => $to], $admin);
            $this->assertSame(200, $step['status']);
            $this->assertSame($to, $step['json']['data']['state']);
        }

        $list = $this->call('GET', '/api/admin/orders', [], $admin);
        $this->assertSame(200, $list['status']);

        // Full refund from a paid-state order → refunded.
        $paid = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'shipped'], $admin);
        $this->assertSame(200, $paid['status']);
        $refund = $this->call('POST', "/api/admin/orders/{$orderId}/refund", ['amount_minor' => 50000, 'reason' => 'oos'], $admin);
        $this->assertSame(422, $refund['status']);

        // Fresh order for the refund happy path.
        $cart2 = $this->call('POST', '/api/carts', []);
        $cartId2 = $cart2['json']['data']['id'];
        $cartHeaders2 = ['X-Cart-Token' => $cart2['json']['data']['access_token']];
        $this->call('POST', "/api/carts/{$cartId2}/lines", ['variant_id' => $variantId, 'qty' => 1], $cartHeaders2);
        $placed2 = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId2], ['Idempotency-Key' => 'adm-key-2', 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'USD'] + $cartHeaders2);
        $orderId2 = $placed2['json']['data']['id'];
        Order::find($orderId2)?->update(['store_id' => 'default', 'channel_id' => 'default']);
        $this->call('POST', "/api/admin/orders/{$orderId2}/transition", ['to' => 'paid'], $admin);
        $refund2 = $this->call('POST', "/api/admin/orders/{$orderId2}/refund", ['amount_minor' => 20000], $admin);
        $this->assertSame(200, $refund2['status']);
        $this->assertSame('partially_refunded', $refund2['json']['data']['state']);
        $overRefund = $this->call('POST', "/api/admin/orders/{$orderId2}/refund", ['amount_minor' => 30001], $admin);
        $this->assertSame(422, $overRefund['status']);
        // One-click refund of the remaining 30000 → fully refunded.
        $remaining = $this->call('POST', "/api/admin/orders/{$orderId2}/refund-remaining", ['reason' => 'oos'], $admin);
        $this->assertSame(200, $remaining['status']);
        $this->assertSame('refunded', $remaining['json']['data']['state']);
        $empty = $this->call('POST', "/api/admin/orders/{$orderId2}/refund-remaining", [], $admin);
        $this->assertSame(422, $empty['status']);
        // Order detail embeds payments inline for staff (no finance hop).
        $detail = $this->call('GET', "/api/orders/{$orderId2}", [], $admin);
        $this->assertSame(200, $detail['status']);
        $this->assertIsArray($detail['json']['data']['payments'] ?? null);
    }

    public function testCouponsAndDemoGuard(): void
    {
        $marketer = $this->loginAs('mkt@shop.test', 'marketer');
        $created = $this->call('POST', '/api/admin/coupons', ['code' => 'SALE10'], $marketer);
        $this->assertSame(201, $created['status']);
        $couponId = $created['json']['data']['id'];

        $list = $this->call('GET', '/api/admin/coupons', [], $marketer);
        $this->assertSame(200, $list['status']);

        $disabled = $this->call('POST', "/api/admin/coupons/{$couponId}/disable", [], $marketer);
        $this->assertSame(200, $disabled['status']);

        $demo = $this->loginAs('demo@sirocommerce.local', 'viewer');
        $blocked = $this->call('POST', '/api/admin/coupons', ['code' => 'HACK'], $demo);
        $this->assertSame(403, $blocked['status']);

        // DemoGuard unit: demo email with a passing role is still read-only on writes.
        $guard = new \App\Middleware\DemoGuardMiddleware();
        $demoReq = new Request('POST', '/api/admin/coupons', [], [], [], '127.0.0.1');
        $demoReq->setUser(['id' => 99, 'email' => 'demo@sirocommerce.local', 'role' => 'admin']);
        $guarded = $guard->handle($demoReq, static fn () => 'passed');
        $this->assertInstanceOf(\Siro\Core\Response::class, $guarded);
        $this->assertSame(403, $guarded->statusCode());

        $readReq = new Request('GET', '/api/admin/coupons', [], [], [], '127.0.0.1');
        $readReq->setUser(['id' => 99, 'email' => 'demo@sirocommerce.local', 'role' => 'admin']);
        $this->assertSame('passed', $guard->handle($readReq, static fn () => 'passed'));
    }
}
