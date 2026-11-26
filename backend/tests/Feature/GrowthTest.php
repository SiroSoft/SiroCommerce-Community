<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CartRepository;
use App\Repositories\CouponRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Models\Channel;
use App\Models\Store;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Sell & Grow (spec 05): promotions end-to-end, SEO engine, analytics funnel.
 */
final class GrowthTest extends TestCase
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

        $products = new ProductRepository();
        $product = $products->store(['name' => 'Ao Khoac', 'slug' => 'ao-khoac', 'status' => 'active']);
        $productId = is_string($product['id'] ?? null) ? $product['id'] : '';
        $carts = new CartRepository();
        $variant = $carts->createVariant(['id' => 'v-gr-1', 'product_id' => $productId, 'sku' => 'GR-001', 'price_minor' => 200000]);
        $variantId = is_string($variant['id'] ?? null) ? $variant['id'] : '';
        (new InventoryRepository())->setOnHand($variantId, 10, 'test');
        $coupons = new CouponRepository();
        $coupons->store(['code' => 'SALE10', 'rules' => '{"type":"percent","value":10}', 'state' => 'active']);
        $coupons->store(['code' => 'OFF50K', 'rules' => '{"type":"fixed","value":50000,"min_subtotal_minor":300000}', 'state' => 'active']);
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
        $repo = new \App\Repositories\UserRepository();
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter($repo));
        $auth->register(['name' => 'm', 'email' => 'mgr@shop.test', 'password' => 'secret123', 'role' => 'marketer']);
        $tokens = $auth->login('mgr@shop.test', 'secret123');
        $access = $tokens->access;
        return ['Authorization' => 'Bearer ' . (is_string($access) ? $access : '')];
    }

    public function testPromotionPreviewAndCheckout(): void
    {
        $preview = $this->call('POST', '/api/promotions/preview', ['code' => 'SALE10', 'subtotal_minor' => 200000]);
        $this->assertSame(200, $preview['status']);
        $this->assertSame(20000, $preview['json']['data']['discount_minor']);

        $missing = $this->call('POST', '/api/promotions/preview', ['code' => 'NOPE', 'subtotal_minor' => 200000]);
        $this->assertSame(422, $missing['status']);

        $minFail = $this->call('POST', '/api/promotions/preview', ['code' => 'OFF50K', 'subtotal_minor' => 100000]);
        $this->assertSame(422, $minFail['status']);

        $cart = $this->call('POST', '/api/carts', []);
        $cartId = $cart['json']['data']['id'];
        $cartToken = $cart['json']['data']['access_token'];
        $cartHeaders = ['X-Cart-Token' => $cartToken];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-gr-1', 'qty' => 1], $cartHeaders);

        $quote = $this->call('GET', "/api/carts/{$cartId}/quote?coupon=SALE10", [], $cartHeaders);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(200000, $quote['json']['data']['subtotal_minor']);
        $this->assertSame(20000, $quote['json']['data']['discount_minor']);
        $this->assertSame(180000, $quote['json']['data']['total_minor']);

        $badQuote = $this->call('GET', "/api/carts/{$cartId}/quote?coupon=NOPE", [], $cartHeaders);
        $this->assertSame(422, $badQuote['status']);

        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'coupon_code' => 'SALE10'], ['Idempotency-Key' => 'gr-key-1', 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'] + $cartHeaders);
        $this->assertSame(201, $placed['status']);
        $this->assertSame(20000, $placed['json']['data']['discount_minor']);
        $this->assertSame(180000, $placed['json']['data']['grand_total_minor']);
    }

    public function testSeoEngine(): void
    {
        $admin = $this->admin();

        $sitemap = $this->call('GET', '/sitemap.xml');
        $this->assertSame(200, $sitemap['status']);

        $robots = $this->call('GET', '/robots.txt');
        $this->assertSame(200, $robots['status']);

        $health = $this->call('GET', '/api/admin/seo/health', [], $admin);
        $this->assertSame(200, $health['status']);
        $this->assertNotCount(0, $health['json']['data']);

        $products = new ProductRepository();
        $product = $products->findBySlug('ao-khoac');
        $productId = is_string($product['id'] ?? null) ? $product['id'] : '';
        $saved = $this->call('PUT', "/api/admin/seo/product/{$productId}", [
            'title' => 'Ao Khoac Dep', 'description' => 'Ao khoac mua dong',
        ], $admin);
        $this->assertSame(200, $saved['status']);

        $health2 = $this->call('GET', '/api/admin/seo/health', [], $admin);
        $this->assertSame(200, $health2['status']);
        $this->assertCount(0, $health2['json']['data']);

        $redirect = $this->call('POST', '/api/admin/redirects', ['from_path' => '/old', 'to_path' => '/p/ao-khoac'], $admin);
        $this->assertSame(200, $redirect['status']);
    }

    public function testAnalyticsFunnel(): void
    {
        $admin = $this->admin();
        foreach (['product.view', 'product.view', 'cart.add', 'checkout.start', 'order.placed'] as $event) {
            $tracked = $this->call('POST', '/api/analytics/track', ['event' => $event, 'session_key' => 's1']);
            $this->assertSame(201, $tracked['status']);
        }
        $bad = $this->call('POST', '/api/analytics/track', ['event' => 'nope']);
        $this->assertSame(422, $bad['status']);

        $funnel = $this->call('GET', '/api/admin/analytics/funnel', [], $admin);
        $this->assertSame(200, $funnel['status']);
        $this->assertSame(2, $funnel['json']['data']['counts']['product.view']);
        $this->assertSame(1, $funnel['json']['data']['counts']['order.placed']);
        $from = date('Y-m-d', strtotime('-30 days'));
        $to = date('Y-m-d');
        $events = $this->call('GET', "/api/admin/analytics/events?from={$from}&to={$to}&per_page=2", [], $admin);
        $this->assertSame(200, $events['status']);
        $this->assertSame(2, $events['json']['meta']['per_page']);
        $this->assertArrayNotHasKey('session_key', $events['json']['data'][0]);
        $export = $this->call('GET', "/api/admin/analytics/export?from={$from}&to={$to}&event=order.placed", [], $admin);
        $this->assertSame(200, $export['status']);
        $this->assertSame(1, $export['json']['data']['schema_version']);
    }

    public function testStorefrontAnalyticsPayloadWithUuidEventIdIsPersisted(): void
    {
        Store::create(['id' => 'default', 'code' => 'default', 'name' => 'Default', 'status' => 'active']);
        Channel::create(['id' => 'web', 'store_id' => 'default', 'code' => 'web', 'name' => 'Web', 'status' => 'active']);
        $tracked = $this->call('POST', '/api/analytics/track', [
            'event' => 'product_view',
            'event_id' => '550e8400-e29b-41d4-a716-446655440000',
            'session_id' => '550e8400e29b41d4a716',
            'properties' => ['product_id' => 'b2c-prod-01', 'category' => 'thoi-trang'],
            'analytics_consent' => true,
            'store_id' => 'default',
            'channel_id' => 'web',
            'locale' => 'vi',
            'currency' => 'VND',
        ]);

        $this->assertSame(201, $tracked['status']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $tracked['json']['data']['event_id']);
    }
}
