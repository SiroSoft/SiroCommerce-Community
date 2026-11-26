<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Click & Collect end to end (spec CLICK_COLLECT_SPEC): point CRUD → public
 * list → pickup quote (zero shipping, source stock check, SLA) → pickup
 * place (point stamped, reserved from point source) → allocate → ready →
 * collect → order picked_up. Router + Container autowiring, no App boot.
 */
final class PickupFlowTest extends TestCase
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
        $carts->createVariant(['id' => 'v-pick-1', 'product_id' => 'p-pick-1', 'sku' => 'PICK-001', 'price_minor' => 15000]);
        (new InventoryRepository())->setOnHand('v-pick-1', 10, 'test', 'default');
        (new InventoryRepository())->setOnHand('v-pick-1', 5, 'test', 'store-hn');
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @param array<string, mixed> $query @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
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
        $auth->register(['name' => 'admin', 'email' => 'pickadmin@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('pickadmin@shop.test', 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @return array{string, array<string, string>} */
    private function cartWithLine(int $qty): array
    {
        $cart = $this->call('POST', '/api/carts', []);
        $this->assertSame(201, $cart['status']);
        $cartId = (string) $cart['json']['data']['id'];
        $headers = ['X-Cart-Token' => (string) $cart['json']['data']['access_token']];
        $line = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-pick-1', 'qty' => $qty], $headers);
        $this->assertSame(201, $line['status']);
        return [$cartId, $headers];
    }

    /** @return array<string, string> */
    private function scope(string $key, string $token): array
    {
        return ['Idempotency-Key' => $key, 'X-Cart-Token' => $token, 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    private function createPoint(array $admin): void
    {
        $created = $this->call('POST', '/api/admin/pickup-points', [
            'code' => 'HN-01', 'name' => 'Hoan Kiem Store', 'source_id' => 'store-hn',
            'address_line' => '1 Trang Tien', 'city' => 'Hanoi', 'sla_hours' => 24,
        ], $admin + ['Idempotency-Key' => 'pick-point-1']);
        $this->assertSame(201, $created['status']);
        $this->assertSame('HN-01', $created['json']['data']['code']);
        $this->assertSame('store-hn', $created['json']['data']['source_id']);
    }

    public function testPointCrudAndPublicList(): void
    {
        $admin = $this->admin();
        $this->createPoint($admin);

        $list = $this->call('GET', '/api/pickup-points');
        $this->assertSame(200, $list['status']);
        $this->assertSame('HN-01', $list['json']['data'][0]['code']);
        $this->assertArrayNotHasKey('id', $list['json']['data'][0]);

        // Duplicate code rejected; bad code rejected; bad SLA rejected.
        $dupe = $this->call('POST', '/api/admin/pickup-points', ['code' => 'hn-01', 'name' => 'Other'], $admin + ['Idempotency-Key' => 'pick-point-2']);
        $this->assertSame(422, $dupe['status']);
        $bad = $this->call('POST', '/api/admin/pickup-points', ['code' => 'x', 'name' => 'Bad'], $admin + ['Idempotency-Key' => 'pick-point-3']);
        $this->assertSame(422, $bad['status']);

        // Disable hides the point from the public list.
        $updated = $this->call('PUT', '/api/admin/pickup-points/HN-01', ['state' => 'disabled'], $admin + ['Idempotency-Key' => 'pick-point-4']);
        $this->assertSame(200, $updated['status']);
        $this->assertSame('disabled', $updated['json']['data']['state']);
        $gone = $this->call('GET', '/api/pickup-points');
        $this->assertSame([], $gone['json']['data']);
    }

    public function testPickupQuoteAndPlace(): void
    {
        $admin = $this->admin();
        $this->createPoint($admin);
        [$cartId, $cartHeaders] = $this->cartWithLine(2);

        $quote = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, ['fulfillment_method' => 'pickup', 'pickup_point' => 'HN-01']);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(30000, $quote['json']['data']['subtotal_minor']);
        $this->assertSame(0, $quote['json']['data']['shipping_minor']);
        $this->assertSame(30000, $quote['json']['data']['total_minor']);
        $pickup = $quote['json']['data']['pickup'];
        $this->assertSame('HN-01', $pickup['code']);
        $this->assertSame(24, $pickup['sla_hours']);
        $this->assertNotEmpty($pickup['ready_by']);

        // Unknown point, point without method, and shortfall all fail closed.
        $unknown = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, ['fulfillment_method' => 'pickup', 'pickup_point' => 'NOPE']);
        $this->assertSame(422, $unknown['status']);
        [$cartId2, $cartHeaders2] = $this->cartWithLine(6);
        $short = $this->call('GET', "/api/carts/{$cartId2}/quote", [], $cartHeaders2, ['fulfillment_method' => 'pickup', 'pickup_point' => 'HN-01']);
        $this->assertSame(422, $short['status']);
        $this->assertStringContainsString('PICK-001', (string) json_encode($short['json']));

        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'fulfillment_method' => 'pickup', 'pickup_point_code' => 'HN-01'], $this->scope('pick-place-1', $cartHeaders['X-Cart-Token']));
        $this->assertSame(201, $placed['status']);
        $this->assertSame(30000, $placed['json']['data']['grand_total_minor']);
        $orderId = (string) $placed['json']['data']['id'];
        $row = Order::find($orderId);
        $this->assertNotNull($row);
        $this->assertSame('HN-01', $row->toArray()['pickup_point_code']);

        // Point without method → 422, nothing placed.
        [$cartId3, $cartHeaders3] = $this->cartWithLine(1);
        $mismatch = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId3, 'pickup_point_code' => 'HN-01'], $this->scope('pick-place-2', $cartHeaders3['X-Cart-Token']));
        $this->assertSame(422, $mismatch['status']);
    }

    public function testHandoverReadyCollectAndOrderStates(): void
    {
        $admin = $this->admin();
        $this->createPoint($admin);
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'fulfillment_method' => 'pickup', 'pickup_point_code' => 'HN-01'], $this->scope('pick-place-3', $cartHeaders['X-Cart-Token']));
        $this->assertSame(201, $placed['status']);
        $orderId = (string) $placed['json']['data']['id'];
        Order::find($orderId)?->update(['store_id' => 'default', 'channel_id' => 'default']);

        $orderLines = \App\Models\OrderLine::query()->where('order_id', $orderId)->get();
        $this->assertCount(1, $orderLines);
        $firstLine = \App\Support\Arr::row($orderLines[0]);
        $lineId = (string) ($firstLine['id'] ?? '');
        $variant = (string) ($firstLine['variant_id'] ?? '');
        $this->assertNotSame('', $lineId);
        $alloc = $this->call('POST', '/api/admin/fulfillment/allocate', [
            'order_id' => $orderId, 'lines' => [['order_line_id' => $lineId, 'variant_id' => $variant, 'qty' => 1, 'source_id' => 'store-hn']],
        ], $admin + ['Idempotency-Key' => 'pick-alloc-1']);
        $this->assertSame(200, $alloc['status']);
        $fulfillmentId = (string) $alloc['json']['data']['id'];
        $this->assertSame('allocated', $alloc['json']['data']['state']);

        // Collect before ready → 422.
        $early = $this->call('POST', "/api/admin/fulfillment/{$fulfillmentId}/collect", [], $admin + ['Idempotency-Key' => 'pick-collect-0']);
        $this->assertSame(422, $early['status']);

        $ready = $this->call('POST', "/api/admin/fulfillment/{$fulfillmentId}/ready", [], $admin + ['Idempotency-Key' => 'pick-ready-1']);
        $this->assertSame(200, $ready['status']);
        $this->assertSame('ready_for_pickup', $ready['json']['data']['state']);
        $this->assertSame('HN-01', $ready['json']['data']['pickup_point_code']);
        $this->assertNotEmpty($ready['json']['data']['ready_by']);

        // Replay returns the same row.
        $again = $this->call('POST', "/api/admin/fulfillment/{$fulfillmentId}/ready", [], $admin + ['Idempotency-Key' => 'pick-ready-2']);
        $this->assertSame(200, $again['status']);
        $this->assertSame('ready_for_pickup', $again['json']['data']['state']);

        $collected = $this->call('POST', "/api/admin/fulfillment/{$fulfillmentId}/collect", [], $admin + ['Idempotency-Key' => 'pick-collect-1']);
        $this->assertSame(200, $collected['status']);
        $this->assertSame('picked_up', $collected['json']['data']['state']);
        $this->assertNotEmpty($collected['json']['data']['picked_up_at']);

        // A collected pickup is never a shipment: deliver stays 422.
        $ship = $this->call('POST', "/api/admin/fulfillment/{$fulfillmentId}/deliver", [], $admin + ['Idempotency-Key' => 'pick-deliver-1']);
        $this->assertSame(422, $ship['status']);

        // Order follows the pickup lane: ready_to_ship → ready_for_pickup → picked_up.
        foreach (['paid', 'processing', 'ready_to_ship'] as $to) {
            $step = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => $to], $admin);
            $this->assertSame(200, $step['status']);
        }
        $pickReady = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'ready_for_pickup'], $admin);
        $this->assertSame(200, $pickReady['status']);
        $noShip = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'shipped'], $admin);
        $this->assertSame(422, $noShip['status']);
        $done = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'picked_up'], $admin);
        $this->assertSame(200, $done['status']);
        $this->assertSame('picked_up', $done['json']['data']['state']);
    }
}
