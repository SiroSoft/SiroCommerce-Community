<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Payment;
use App\Modules\Promotion\Application\FlashSaleService;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class EdgeCase2Test extends TestCase
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

    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
            $query = is_array($query) ? $query : [];
        }
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array{cartId: string, token: string} */
    private function openCart(): array
    {
        $cart = $this->call('POST', '/api/carts', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        return ['cartId' => $cart['json']['data']['id'], 'token' => $cart['json']['data']['access_token']];
    }

    public function testFlashPriceUsedAtPlaceAndCapEnforced(): void
    {
        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-fl-1', 'product_id' => 'p-fl-1', 'sku' => 'FL-1', 'price_minor' => 20000]);
        (new InventoryRepository())->setOnHand('v-fl-1', 100, 'test');
        $svc = new FlashSaleService();
        $sale = $svc->createSale(['name' => 'edge', 'starts_at' => date('c', time() - 60), 'ends_at' => date('c', time() + 3600), 'state' => 'active']);
        $svc->addItem($sale['id'], ['variant_id' => 'v-fl-1', 'sale_price_minor' => 12000, 'cap_qty' => 1]);

        $c = $this->openCart();
        $add = $this->call('POST', "/api/carts/{$c['cartId']}/lines", ['variant_id' => 'v-fl-1', 'qty' => 1], ['X-Cart-Token' => $c['token']]);
        $this->assertSame(201, $add['status']);
        $this->assertSame(12000, $add['json']['data']['unit_price_minor']);

        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $c['cartId'], 'payment_method' => 'cod'], ['Idempotency-Key' => 'edge-fl-' . bin2hex(random_bytes(4)), 'X-Cart-Token' => $c['token'], 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $this->assertSame(201, $placed['status']);
        $this->assertSame(12000, $placed['json']['data']['grand_total_minor']);

        // cap exhausted: discounted units are gone, but regular-price purchase still works.
        $c2 = $this->openCart();
        $add2 = $this->call('POST', "/api/carts/{$c2['cartId']}/lines", ['variant_id' => 'v-fl-1', 'qty' => 1], ['X-Cart-Token' => $c2['token']]);
        $this->assertSame(201, $add2['status']);
        $this->assertSame(20000, $add2['json']['data']['unit_price_minor']);
        $placed2 = $this->call('POST', '/api/checkout/place', ['cart_id' => $c2['cartId'], 'payment_method' => 'cod'], ['Idempotency-Key' => 'edge-fl-' . bin2hex(random_bytes(4)), 'X-Cart-Token' => $c2['token'], 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $this->assertSame(201, $placed2['status']);
        $this->assertSame(20000, $placed2['json']['data']['grand_total_minor']);
    }

    public function testFlashEndedBetweenAddAndPlace(): void
    {
        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-fl-2', 'product_id' => 'p-fl-2', 'sku' => 'FL-2', 'price_minor' => 20000]);
        (new InventoryRepository())->setOnHand('v-fl-2', 100, 'test');
        $svc = new FlashSaleService();
        $sale = $svc->createSale(['name' => 'short', 'starts_at' => date('c', time() - 60), 'ends_at' => date('c', time() + 3600), 'state' => 'active']);
        $svc->addItem($sale['id'], ['variant_id' => 'v-fl-2', 'sale_price_minor' => 9000, 'cap_qty' => 0]);

        $c = $this->openCart();
        $add = $this->call('POST', "/api/carts/{$c['cartId']}/lines", ['variant_id' => 'v-fl-2', 'qty' => 1], ['X-Cart-Token' => $c['token']]);
        $this->assertSame(201, $add['status']);
        // expire the sale before placing
        $admin = $this->adminHeaders();
        $this->call('PUT', "/api/admin/flash-sales/{$sale['id']}", ['state' => 'archived'], $admin);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $c['cartId'], 'payment_method' => 'cod'], ['Idempotency-Key' => 'edge-fl-' . bin2hex(random_bytes(4)), 'X-Cart-Token' => $c['token'], 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $this->assertSame(422, $placed['status']);
    }

    public function testUpdateLineQtyZeroRejected(): void
    {
        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-qz-1', 'product_id' => 'p-qz-1', 'sku' => 'QZ-1', 'price_minor' => 1000]);
        (new InventoryRepository())->setOnHand('v-qz-1', 100, 'test');
        $c = $this->openCart();
        $add = $this->call('POST', "/api/carts/{$c['cartId']}/lines", ['variant_id' => 'v-qz-1', 'qty' => 2], ['X-Cart-Token' => $c['token']]);
        $lineId = $add['json']['data']['id'];
        $upd = $this->call('PATCH', "/api/carts/{$c['cartId']}/lines/{$lineId}", ['qty' => 0], ['X-Cart-Token' => $c['token']]);
        $this->assertSame(422, $upd['status']);
        $upd2 = $this->call('PATCH', "/api/carts/{$c['cartId']}/lines/{$lineId}", ['qty' => 100], ['X-Cart-Token' => $c['token']]);
        $this->assertSame(422, $upd2['status']);
    }

    public function testBulkOver200Rejected(): void
    {
        $admin = $this->adminHeaders();
        // real company + real member so we pass the membership gate
        $memberEmail = 'bulkmember-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter(new \App\Repositories\UserRepository()));
        $auth->register(['name' => 'member', 'email' => $memberEmail, 'password' => 'secret123', 'role' => 'customer']);
        $memberTokens = $auth->login($memberEmail, 'secret123');
        $memberRow = \Siro\Core\Database::table('users')->where('email', $memberEmail)->first();
        $memberId = is_array($memberRow) ? (int) ($memberRow['id'] ?? 0) : 0;
        $this->assertGreaterThan(0, $memberId);
        $company = $this->call('POST', '/api/admin/b2b/companies', ['name' => 'Bulk Co'], $admin);
        $this->assertSame(201, $company['status']);
        $companyId = (string) ($company['json']['data']['id'] ?? '');
        \Siro\Core\Database::table('company_members')->insert([
            'id' => \App\Support\Ids::new(), 'company_id' => $companyId, 'user_id' => $memberId, 'role' => 'buyer', 'status' => 'active',
        ]);
        $memberHeaders = ['Authorization' => 'Bearer ' . $memberTokens->access];
        $lines = [];
        for ($i = 0; $i < 201; $i++) $lines[] = ['variant_id' => 'v', 'qty' => 1];
        $r = $this->call('POST', "/api/v2/b2b/companies/{$companyId}/orders/validate-bulk", ['lines' => $lines], array_merge($memberHeaders, ['Idempotency-Key' => 'bulk-' . bin2hex(random_bytes(4))]));
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('200', (string) json_encode($r['json']));
        // exactly 200 passes the limit gate (may fail later on pricing, never on the limit)
        $lines200 = [];
        for ($i = 0; $i < 200; $i++) $lines200[] = ['variant_id' => 'v', 'qty' => 1];
        $r2 = $this->call('POST', "/api/v2/b2b/companies/{$companyId}/orders/validate-bulk", ['lines' => $lines200], array_merge($memberHeaders, ['Idempotency-Key' => 'bulk-' . bin2hex(random_bytes(4))]));
        $this->assertStringNotContainsString('limited to 200', (string) json_encode($r2['json']));
    }

    public function testMemberDiscountAppliesForAuthenticatedTier(): void
    {
        $email = 'member-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter(new \App\Repositories\UserRepository()));
        $auth->register(['name' => 'member', 'email' => $email, 'password' => 'secret123', 'role' => 'customer']);
        $tokens = $auth->login($email, 'secret123');
        $userRow = \Siro\Core\Database::table('users')->where('email', $email)->first();
        $uid = is_array($userRow) ? (string) ($userRow['id'] ?? '') : '';
        $this->assertNotSame('', $uid);
        \Siro\Core\Database::table('customer_loyalty_snapshot')->insert([
            'id' => \App\Support\Ids::new(), 'customer_id' => $uid, 'order_id' => 'o-mem', 'tier' => 'gold', 'points_earned' => 10, 'applied_at' => date('Y-m-d H:i:s'),
        ]);
        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-mem-9', 'product_id' => 'p-mem-9', 'sku' => 'MEM-9', 'price_minor' => 20000]);
        (new InventoryRepository())->setOnHand('v-mem-9', 50, 'test');
        (new \App\Modules\Promotion\Application\MemberPriceService())->setPrice(['variant_id' => 'v-mem-9', 'tier' => 'gold', 'price_minor' => 15000, 'currency' => 'VND']);
        $c = $this->openCart();
        $this->call('POST', "/api/carts/{$c['cartId']}/lines", ['variant_id' => 'v-mem-9', 'qty' => 1], ['X-Cart-Token' => $c['token']]);
        $memberHeaders = ['Authorization' => 'Bearer ' . $tokens->access, 'X-Cart-Token' => $c['token'], 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
        $quote = $this->call('GET', "/api/carts/{$c['cartId']}/quote", [], $memberHeaders);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(5000, $quote['json']['data']['member_discount_minor']);
        $this->assertSame('gold', $quote['json']['data']['member_tier']);
        $this->assertSame(15000, $quote['json']['data']['total_minor']);
        // guest quote (no auth) gets no member discount
        $guestQuote = $this->call('GET', "/api/carts/{$c['cartId']}/quote", [], ['X-Cart-Token' => $c['token']]);
        $this->assertSame(0, $guestQuote['json']['data']['member_discount_minor'] ?? 0);
        // member + percent coupon: coupon evaluates on the member-reduced base
        (new \App\Repositories\CouponRepository())->store(['code' => 'MEM10', 'rules' => '{"type":"percent","value":10}', 'state' => 'active']);
        $comboQuote = $this->call('GET', "/api/carts/{$c['cartId']}/quote?coupon=MEM10", [], $memberHeaders);
        $this->assertSame(200, $comboQuote['status']);
        $this->assertSame(5000, $comboQuote['json']['data']['member_discount_minor']);
        $this->assertSame(1500, $comboQuote['json']['data']['discount_minor']); // 10% of (20000-5000), not 2000
        $this->assertSame(13500, $comboQuote['json']['data']['total_minor']);
        // place as member keeps the discount
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $c['cartId'], 'payment_method' => 'cod'], ['Idempotency-Key' => 'edge-mem-' . bin2hex(random_bytes(4)), 'X-Cart-Token' => $c['token'], 'Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $this->assertSame(201, $placed['status']);
        $this->assertSame(15000, $placed['json']['data']['grand_total_minor']);
    }

    public function testCartShowSurfacesSlotAndFlash(): void
    {
        $admin = $this->adminHeaders();
        $carts = new CartRepository();
        $vid = 'v-show-' . bin2hex(random_bytes(4));
        $carts->createVariant(['id' => $vid, 'product_id' => 'p-show-' . bin2hex(random_bytes(4)), 'sku' => 'SHOW-' . bin2hex(random_bytes(2)), 'price_minor' => 5000]);
        $prodId = \App\Models\ProductVariant::query()->where('id', $vid)->first()->toArray()['product_id'];
        \App\Models\Product::create(['id' => $prodId, 'name' => 'SHOW', 'slug' => 'show-' . bin2hex(random_bytes(3)), 'type' => 'booking', 'status' => 'active', 'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        // Freeze one timestamp: two separate time() calls can straddle a
        // second boundary, and the slot lookup is an exact string match.
        $slotAt = time() + 3600;
        $slot = $this->call('POST', "/api/admin/products/{$vid}/booking-slots", [
            'starts_at' => date('c', $slotAt), 'ends_at' => date('c', $slotAt + 3600), 'capacity' => 5,
        ], array_merge($admin, ['Idempotency-Key' => 'show-slot-' . bin2hex(random_bytes(4))]));
        $this->assertSame(201, $slot['status']);
        $c = $this->openCart();
        $add = $this->call('POST', "/api/carts/{$c['cartId']}/lines", ['variant_id' => $vid, 'qty' => 1, 'slot_start' => date('Y-m-d H:i:s', $slotAt)], ['X-Cart-Token' => $c['token']]);
        $this->assertSame(201, $add['status']);
        $show = $this->call('GET', "/api/carts/{$c['cartId']}", [], ['X-Cart-Token' => $c['token']]);
        $this->assertSame(200, $show['status']);
        $line = $show['json']['data']['lines'][0];
        $this->assertNotEmpty($line['slot_start']);
        $this->assertNotEmpty($line['booking_slot_id']);
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $email = 'edge-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter(new \App\Repositories\UserRepository()));
        $auth->register(['name' => 'admin', 'email' => $email, 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }
}
