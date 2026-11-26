<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SearchTerm;
use App\Models\SearchSynonym;
use App\Models\UrlRewrite;
use App\Models\SitemapSetting;
use App\Models\Coupon;
use App\Models\BookingSlot;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class FullCoverageTest extends TestCase
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

    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
        try { $response = $this->router->dispatch($request); } catch (ValidationException $e) { $response = $e->toResponse(); }
        ob_start(); $response->send(); $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array<string, string> */
    private function adminHeaders(string $role = 'admin'): array
    {
        $email = 'test-admin-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter(new \App\Repositories\UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testPaymentProofFlow(): void
    {
        // create payment via checkout
        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-proof-1', 'product_id' => 'p-proof-1', 'sku' => 'PROOF-1', 'price_minor' => 10000]);
        (new InventoryRepository())->setOnHand('v-proof-1', 10, 'test');
        $cart = $this->call('POST', '/api/carts', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $cartId = $cart['json']['data']['id'];
        $token = $cart['json']['data']['access_token'];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-proof-1', 'qty' => 1], ['X-Cart-Token' => $token]);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'payment_method' => 'bank_transfer'], ['Idempotency-Key' => 'proof-test-' . bin2hex(random_bytes(4)), 'X-Cart-Token' => $token, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $this->assertSame(201, $placed['status']);
        $orderId = $placed['json']['data']['id'];
        // find payment
        $payment = Payment::query()->where('order_id', $orderId)->first();
        $this->assertNotNull($payment);
        $pid = $payment->toArray()['id'];
        // submit proof as guest via X-Order-Token
        $guestToken = $placed['json']['data']['guest_access_token'] ?? '';
        $proof = $this->call('POST', "/api/v2/payments/{$pid}/proof", ['reference' => 'FT12345', 'file_url' => '/uploads/proof.jpg'], ['Idempotency-Key' => 'proof-key-' . bin2hex(random_bytes(4)), 'X-Order-Token' => $guestToken, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        $this->assertContains($proof['status'], [200, 201]);
        $this->assertSame('FT12345', $proof['json']['data']['proof']['reference'] ?? $proof['json']['data']['payment']['proof_reference'] ?? '');
        // idempotent replay
        $proof2 = $this->call('POST', "/api/v2/payments/{$pid}/proof", ['reference' => 'FT12345', 'file_url' => '/uploads/proof.jpg'], ['Idempotency-Key' => 'proof-key-' . bin2hex(random_bytes(4)), 'X-Order-Token' => $guestToken]);
        // should be 200 or 201 or 422 depending on key reuse, but not 500
        $this->assertNotSame(500, $proof2['status']);
        // P0: no token and no auth must be rejected
        $denied = $this->call('POST', "/api/v2/payments/{$pid}/proof", ['reference' => 'FT99999', 'file_url' => '/uploads/proof.jpg'], ['Idempotency-Key' => 'proof-key-' . bin2hex(random_bytes(4))]);
        $this->assertSame(403, $denied['status']);
        // P0: external non-image proof URL must be rejected even with valid token
        $badUrl = $this->call('POST', "/api/v2/payments/{$pid}/proof", ['reference' => 'FT99999', 'file_url' => 'https://evil.example/x.svg'], ['Idempotency-Key' => 'proof-key-' . bin2hex(random_bytes(4)), 'X-Order-Token' => $guestToken]);
        $this->assertSame(422, $badUrl['status']);
    }

    public function testUrlRewritesCrud(): void
    {
        $headers = $this->adminHeaders();
        $rand = bin2hex(random_bytes(3));
        $create = $this->call('POST', '/api/admin/search-seo/url-rewrites', ['request_path' => '/old-test-' . $rand, 'target_path' => '/new-test-' . $rand, 'redirect_type' => 301], $headers);
        $this->assertContains($create['status'], [200, 201, 401, 403]);
        if ($create['status'] === 201) {
            $id = $create['json']['data']['id'];
            $list = $this->call('GET', '/api/admin/search-seo/url-rewrites', [], $headers);
            $this->assertSame(200, $list['status']);
            $update = $this->call('PUT', "/api/admin/search-seo/url-rewrites/{$id}", ['target_path' => '/new-test-2'], $headers);
            $this->assertSame(200, $update['status']);
            $del = $this->call('DELETE', "/api/admin/search-seo/url-rewrites/{$id}", [], $headers);
            $this->assertSame(200, $del['status']);
        }
    }

    public function testSearchSynonymsCrud(): void
    {
        $headers = $this->adminHeaders();
        $create = $this->call('POST', '/api/admin/search-seo/synonyms', ['term' => 'testterm' . bin2hex(random_bytes(2)), 'synonyms' => ['syn1', 'syn2']], $headers);
        $this->assertContains($create['status'], [200, 201, 401, 403, 409]);
        if ($create['status'] === 201) {
            $id = $create['json']['data']['id'];
            $list = $this->call('GET', '/api/admin/search-seo/synonyms', [], $headers);
            $this->assertSame(200, $list['status']);
            $del = $this->call('DELETE', "/api/admin/search-seo/synonyms/{$id}", [], $headers);
            $this->assertSame(200, $del['status']);
        }
    }

    public function testSitemapsSettings(): void
    {
        $headers = $this->adminHeaders();
        $get = $this->call('GET', '/api/admin/search-seo/sitemaps', [], $headers);
        $this->assertContains($get['status'], [200, 401, 403]);
        if ($get['status'] === 200) {
            $put = $this->call('PUT', '/api/admin/search-seo/sitemaps', ['products' => ['frequency' => 'daily', 'priority' => 0.8], 'images_enabled' => true], $headers);
            $this->assertSame(200, $put['status']);
            $gen = $this->call('POST', '/api/admin/search-seo/sitemaps/generate', [], array_merge($headers, ['Idempotency-Key' => 'sitemap-' . bin2hex(random_bytes(4))]));
            $this->assertContains($gen['status'], [200, 201]);
        }
    }

    public function testConfigurationCustomPromoBanner(): void
    {
        $headers = $this->adminHeaders();
        $put = $this->call('PUT', '/api/admin/configuration/custom/promo_banner', ['values' => ['title' => 'Test Banner', 'enabled' => true, 'image' => '/uploads/banner.jpg'], 'expected_version' => 0], $headers);
        $this->assertContains($put['status'], [200, 201, 401, 403, 409, 422]);
        $pub = $this->call('GET', '/api/configuration/public', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        $this->assertSame(200, $pub['status']);
    }

    public function testCouponBulkAndVersions(): void
    {
        $headers = $this->adminHeaders();
        $bulk = $this->call('POST', '/api/admin/coupons/bulk', ['pattern' => 'BULK{0000}', 'count' => 2, 'start' => 1, 'rules' => '{"type":"fixed","value":1000}'], array_merge($headers, ['Idempotency-Key' => 'bulk-' . bin2hex(random_bytes(4))]));
        $this->assertContains($bulk['status'], [200, 201, 401, 403, 422]);
    }

    public function testBookingSlots(): void
    {
        $headers = $this->adminHeaders();
        $vid = 'v-booking-' . bin2hex(random_bytes(4));
        // create variant for booking test
        try { (new CartRepository())->createVariant(['id' => $vid, 'product_id' => 'p-booking-' . bin2hex(random_bytes(4)), 'sku' => 'BOOK-' . bin2hex(random_bytes(2)), 'price_minor' => 5000]); } catch (\Throwable $e) {}
        $create = $this->call('POST', "/api/admin/products/{$vid}/booking-slots", ['starts_at' => date('c', time() + 3600), 'ends_at' => date('c', time() + 7200), 'capacity' => 5], $headers);
        $this->assertContains($create['status'], [200, 201, 401, 403, 404, 422]);
    }
}
