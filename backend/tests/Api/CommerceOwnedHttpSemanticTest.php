<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Models\ProductVariant;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Customer\Application\CustomerService;
use App\Modules\Platform\Infrastructure\CustomerStoreAdapter;
use App\Repositories\ProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/** Exact owned-route HTTP behavior, including envelopes and authorization failures. */
final class CommerceOwnedHttpSemanticTest extends TestCase
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

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        try {
            $response = $this->router->dispatch(new Request($method, $path, [], $headers, $body, '127.0.0.1'));
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array<string, string> */
    private function login(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        return ['Authorization' => 'Bearer ' . $auth->login($email, 'secret123')->access];
    }

    public function testPublicCatalogQuoteAndProductTypeRoutesReturnSemanticEnvelopes(): void
    {
        $scope = ['X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
        foreach (['GET /api/products', 'GET /api/products/missing-product', 'GET /api/v2/catalog/brands', 'GET /api/v2/catalog/categories', 'GET /api/v2/catalog/product-types', 'GET /api/v2/catalog/tags'] as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            $result = $this->call($method, $path, [], str_starts_with($path, '/api/v2/catalog/') ? $scope : []);
            self::assertContains($result['status'], [200, 404], $operation);
            self::assertIsArray($result['json'], $operation);
            self::assertArrayHasKey('success', $result['json'], $operation);
        }

        $types = $this->call('GET', '/api/v2/catalog/product-types', [], $scope);
        self::assertSame(['simple', 'configurable', 'grouped', 'bundle', 'virtual', 'downloadable', 'voucher', 'booking'], array_column($types['json']['data'], 'type'));
        self::assertSame(400, $this->call('GET', '/api/v2/catalog/tags', [], ['X-Store-Id' => 'bad space'])['status']);

        $quote = $this->call('POST', '/api/v2/checkout/quote', []);
        self::assertSame(422, $quote['status']);
        self::assertFalse($quote['json']['success']);

        $bundle = $this->call('GET', '/api/v2/products/missing-product/bundle-quote');
        self::assertSame(422, $bundle['status']);
        self::assertFalse($bundle['json']['success']);

        $download = $this->call('GET', '/api/v2/downloads/not-a-token');
        self::assertSame(404, $download['status']);
        self::assertSame('NOT_FOUND', $download['json']['meta']['error_code']);
    }

    public function testAdminCatalogSubroutesPersistAndReturnExactHttpResults(): void
    {
        $admin = $this->login('commerce-catalog-owner@test', 'owner');
        $admin += ['X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
        $products = new ProductRepository();
        $products->store(['id' => 'http-catalog-product', 'name' => 'HTTP Catalog', 'slug' => 'http-catalog', 'status' => 'draft']);
        ProductVariant::create(['id' => 'http-catalog-variant', 'product_id' => 'http-catalog-product', 'sku' => 'HTTP-CATALOG', 'price_minor' => 1000, 'stock' => 5]);

        self::assertSame(200, $this->call('GET', '/api/admin/products/http-catalog-product/options', [], $admin)['status']);
        $option = $this->call('POST', '/api/admin/products/http-catalog-product/options', ['name' => 'Color'], $admin);
        self::assertSame(201, $option['status']);
        $optionId = $option['json']['data']['id'];
        self::assertSame(201, $this->call('POST', "/api/admin/products/http-catalog-product/options/{$optionId}/values", ['value' => 'Blue'], $admin)['status']);

        self::assertSame(200, $this->call('GET', '/api/admin/products/http-catalog-product/media', [], $admin)['status']);
        $media = $this->call('POST', '/api/admin/products/http-catalog-product/media', ['url' => 'https://cdn.test/http.jpg', 'mime_type' => 'image/jpeg'], $admin);
        self::assertSame(201, $media['status']);
        $mediaId = $media['json']['data']['id'];
        self::assertSame(200, $this->call('PUT', '/api/admin/products/http-catalog-product/media/order', ['media_ids' => [$mediaId]], $admin)['status']);
        self::assertSame(204, $this->call('DELETE', "/api/admin/products/http-catalog-product/media/{$mediaId}", [], $admin)['status']);

        self::assertSame(200, $this->call('GET', '/api/admin/products/http-catalog-product/variants', [], $admin)['status']);
        self::assertSame(201, $this->call('POST', '/api/admin/products/http-catalog-product/variants', ['sku' => 'HTTP-CATALOG-2', 'price_minor' => 1200], $admin)['status']);
        self::assertSame(201, $this->call('POST', '/api/admin/product-components', ['product_id' => 'http-catalog-product', 'component_variant_id' => 'http-catalog-variant', 'qty' => 1], $admin)['status']);
        $asset = $this->call('POST', '/api/admin/digital-assets', ['variant_id' => 'http-catalog-variant', 'name' => 'file.txt', 'url' => 'file.txt', 'access_key' => 'secret'], $admin);
        self::assertSame(201, $asset['status']);
        $issue = $this->call('POST', '/api/admin/orders/http-order/downloads', ['asset_id' => 'missing-asset', 'order_id' => 'http-order'], $admin);
        self::assertSame(404, $issue['status']);
        $import = $this->call('POST', '/api/admin/catalog/import', ['rows' => [['name' => 'Imported', 'slug' => 'imported-http']]], $admin);
        self::assertSame(200, $import['status']);
        self::assertTrue($import['json']['success']);
    }

    public function testAccountOrdersReturnsAndPaymentCommandsEnforceAuthAndEnvelopes(): void
    {
        $customer = $this->login('commerce-customer@example.test', 'customer');
        (new CustomerService(new CustomerStoreAdapter()))->register('commerce-customer@example.test', 'Customer');
        $orders = $this->call('GET', '/api/account/orders', [], $customer);
        self::assertSame(200, $orders['status']);
        self::assertArrayHasKey('meta', $orders['json']);
        self::assertSame(404, $this->call('GET', '/api/account/orders/missing-order', [], $customer)['status']);
        self::assertSame(404, $this->call('GET', '/api/orders/missing-order/returns')['status']);

        $admin = $this->login('commerce-payment-owner@test', 'owner');
        $admin += ['X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
        (new OrderRepository())->createPayment(['id' => 'payment-http', 'order_id' => 'http-order', 'provider' => 'cod', 'amount_minor' => 100, 'currency' => 'VND', 'state' => 'authorized', 'idempotency_key' => 'payment-http-auth']);
        foreach (['capture', 'refund', 'void'] as $operation) {
            $path = "/api/v2/payments/payment-http/{$operation}";
            $unauthorized = $this->call('POST', $path, ['amount_minor' => 100]);
            self::assertSame(401, $unauthorized['status'], $path);
            $missingKey = $this->call('POST', $path, ['amount_minor' => 100], $admin);
            self::assertSame(422, $missingKey['status'], $path);
            self::assertFalse($missingKey['json']['success'], $path);
        }
        $void = $this->call('POST', '/api/v2/payments/payment-http/void', ['amount_minor' => 100, 'currency' => 'VND'], ['Idempotency-Key' => 'payment-http-void'] + $admin);
        self::assertSame(200, $void['status']);
        self::assertSame('voided', $void['json']['data']['state']);
        self::assertSame(404, $this->call('GET', '/api/admin/orders/http-order', [], $admin)['status']);
        $reconcile = $this->call('POST', '/api/admin/orders/http-order/promotion-allocations/reconcile', [], $admin);
        self::assertSame(422, $reconcile['status']);
        self::assertFalse($reconcile['json']['success']);
    }

    public function testSignedWebhookRejectsInvalidRawBodySignatureAtExactRoute(): void
    {
        putenv('STRIPE_WEBHOOK_SECRET=http-semantic-secret');
        putenv('PAYMENT_STRIPE_WEBHOOK_SECRET=http-semantic-secret');
        putenv('STRIPE_SECRET_KEY=http-semantic-key');
        putenv('PAYMENT_MODE=sandbox');
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'http-semantic-secret';
        $_ENV['PAYMENT_STRIPE_WEBHOOK_SECRET'] = 'http-semantic-secret';
        $_ENV['STRIPE_SECRET_KEY'] = 'http-semantic-key';
        $_ENV['PAYMENT_MODE'] = 'sandbox';
        $result = $this->call('POST', '/api/webhooks/stripe', ['event_id' => 'http-event'], [
            'X-Payment-Signature' => 'sha256=' . hash_hmac('sha256', '{"event_id":"different"}', 'http-semantic-secret'),
            'X-Payment-Provider' => 'stripe',
        ]);
        self::assertSame(401, $result['status']);
        self::assertFalse($result['json']['success']);
    }
}
