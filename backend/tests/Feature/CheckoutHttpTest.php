<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * HTTP round-trip Phase 1 (spec 03§2): carts → lines → quote → place
 * (Idempotency-Key) → order show → webhooks. Router + Container autowiring,
 * no full App boot required.
 */
final class CheckoutHttpTest extends TestCase
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
        $carts->createVariant([
            'id' => 'v-http-1',
            'product_id' => 'p-http-1',
            'sku' => 'HTTP-001',
            'price_minor' => 15000,
        ]);
        (new InventoryRepository())->setOnHand('v-http-1', 10, 'test');
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers */
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
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    public function testFullHttpCheckout(): void
    {
        $cart = $this->call('POST', '/api/carts', []);
        $this->assertSame(201, $cart['status']);
        $cartId = $cart['json']['data']['id'];
        $cartToken = $cart['json']['data']['access_token'];
        $this->assertSame(403, $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-http-1', 'qty' => 2])['status']);
        $this->assertSame(403, $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-http-1', 'qty' => 2], ['X-Cart-Token' => str_repeat('0', 64)])['status']);

        $line = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-http-1', 'qty' => 2], ['X-Cart-Token' => $cartToken]);
        $this->assertSame(201, $line['status']);

        $quote = $this->call('GET', "/api/carts/{$cartId}/quote", [], ['X-Cart-Token' => $cartToken]);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(30000, $quote['json']['data']['subtotal_minor']);

        $noKey = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], ['X-Cart-Token' => $cartToken]);
        $this->assertSame(422, $noKey['status']);

        $scopeHeaders = ['Idempotency-Key' => 'http-key-1', 'X-Cart-Token' => $cartToken, 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], $scopeHeaders);
        $this->assertSame(201, $placed['status']);
        $orderId = $placed['json']['data']['id'];
        $guestToken = $placed['json']['data']['guest_access_token'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $guestToken);
        $this->assertSame('awaiting_payment', $placed['json']['data']['state']);

        $again = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], $scopeHeaders);
        $this->assertSame(201, $again['status']);
        $this->assertSame($orderId, $again['json']['data']['id']);

        $forbidden = $this->call('GET', "/api/orders/{$orderId}");
        $this->assertSame(403, $forbidden['status']);
        $order = $this->call('GET', "/api/orders/{$orderId}", [], ['X-Order-Token' => $guestToken]);
        $this->assertSame(200, $order['status']);
        $this->assertCount(1, $order['json']['data']['lines']);
        $this->assertCount(1, $order['json']['data']['transitions']);

        $missing = $this->call('GET', '/api/orders/does-not-exist');
        $this->assertSame(403, $missing['status']);
    }

    public function testWebhooks(): void
    {
        $cod = $this->call('POST', '/api/webhooks/cod', []);
        $this->assertSame(404, $cod['status']);

        $unknown = $this->call('POST', '/api/webhooks/stripe', []);
        // Stripe is a configured provider surface; an unsigned request is an
        // authentication failure, not an unknown route/provider response.
        $this->assertSame(401, $unknown['status']);
    }

    public function testValidationErrors(): void
    {
        $cart = $this->call('POST', '/api/carts', []);
        $cartId = $cart['json']['data']['id'];
        $bad = $this->call('POST', "/api/carts/{$cartId}/lines", ['qty' => 1], ['X-Cart-Token' => $cart['json']['data']['access_token']]);
        $this->assertSame(422, $bad['status']);
    }

    public function testGuestEmailValidationHappensBeforeOrderCreation(): void
    {
        $cart = $this->call('POST', '/api/carts', []);
        $cartId = $cart['json']['data']['id'];
        $token = $cart['json']['data']['access_token'];
        $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-http-1', 'qty' => 1], ['X-Cart-Token' => $token]);

        $response = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'guest_email' => 'not-an-email'], ['Idempotency-Key' => 'invalid-email-key', 'X-Cart-Token' => $token, 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $this->assertSame(422, $response['status']);
        $this->assertSame('Enter a valid email address', $response['json']['meta']['errors']['guest_email'][0]);
    }
}
