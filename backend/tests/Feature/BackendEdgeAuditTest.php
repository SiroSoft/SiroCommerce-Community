<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Promotion\Application\GiftCreditService;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Backend edge audit: pins idempotency, refund-to-credit, CSV duplicate,
 * redirect target sanitation, search escaping, and sitemap limits to the
 * behaviour the HTTP surface already promises.
 */
final class BackendEdgeAuditTest extends TestCase
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
        $carts->createVariant(['id' => 'v-edge-audit-1', 'product_id' => 'p-edge-audit-1', 'sku' => 'EDGE-AUDIT-1', 'price_minor' => 15000]);
        (new InventoryRepository())->setOnHand('v-edge-audit-1', 20, 'test');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @return array{status: int, json: mixed, output: string, headers: array<string, string>}
     */
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
        return [
            'status' => $response->statusCode(),
            'json' => json_decode($output, true),
            'output' => $output,
            'headers' => $response->headers(),
        ];
    }

    /** @return array<string, string> */
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return [
            'Authorization' => 'Bearer ' . $tokens->access,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];
    }

    /** @return array{string, array<string, string>} */
    private function cartWithLine(int $qty = 1): array
    {
        $cart = $this->call('POST', '/api/carts', []);
        self::assertSame(201, $cart['status']);
        $cartId = (string) $cart['json']['data']['id'];
        $headers = ['X-Cart-Token' => (string) $cart['json']['data']['access_token']];
        $line = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-edge-audit-1', 'qty' => $qty], $headers);
        self::assertSame(201, $line['status']);
        return [$cartId, $headers];
    }

    /** @return array<string, string> */
    private function scope(string $key, string $token): array
    {
        return [
            'Idempotency-Key' => $key,
            'X-Cart-Token' => $token,
            'X-Store-Id' => 'storefront',
            'X-Channel-Id' => 'web',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];
    }

    public function testCheckoutIdempotencyReplaysSameOrderAndCreatesANewOneForANewKey(): void
    {
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $headers = $this->scope('edge-audit-place-1', $cartHeaders['X-Cart-Token']);

        $first = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], $headers);
        self::assertSame(201, $first['status']);
        $orderId = (string) $first['json']['data']['id'];

        $replay = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], $headers);
        self::assertSame(201, $replay['status']);
        self::assertSame($orderId, (string) $replay['json']['data']['id']);
        self::assertSame(1, (int) Database::table('orders')->where('idempotency_key', 'edge-audit-place-1')->count());

        [$cartId2, $cartHeaders2] = $this->cartWithLine(1);
        $second = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId2], $this->scope('edge-audit-place-2', $cartHeaders2['X-Cart-Token']));
        self::assertSame(201, $second['status']);
        self::assertNotSame($orderId, (string) $second['json']['data']['id']);
        $item = Database::table('inventory_items')->where('variant_id', 'v-edge-audit-1')->first();
        self::assertNotNull($item);
        self::assertSame(18, (int) ($item['on_hand'] ?? 0) - (int) ($item['reserved'] ?? 0));
    }

    public function testRefundToCreditWithANewKeyCannotCreditTheSameOrderTwice(): void
    {
        $member = $this->loginAs('edge-audit-member@shop.test', 'customer');
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $placed = $this->call(
            'POST',
            '/api/checkout/place',
            ['cart_id' => $cartId],
            ['Idempotency-Key' => 'edge-audit-credit-place', 'X-Cart-Token' => $cartHeaders['X-Cart-Token'], 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND', 'Authorization' => $member['Authorization']]
        );
        self::assertSame(201, $placed['status']);
        $orderId = (string) $placed['json']['data']['id'];
        Order::find($orderId)?->update(['store_id' => 'default', 'channel_id' => 'default']);

        $admin = $this->loginAs('edge-audit-admin@shop.test', 'admin');
        $paid = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'paid'], $admin);
        self::assertSame(200, $paid['status']);

        $first = $this->call('POST', "/api/admin/orders/{$orderId}/refund-to-credit", ['reason' => 'audit'], $admin + ['Idempotency-Key' => 'edge-audit-credit-1']);
        self::assertSame(200, $first['status']);
        self::assertSame(15000, (int) $first['json']['data']['credit']['balance_minor']);

        $second = $this->call('POST', "/api/admin/orders/{$orderId}/refund-to-credit", ['reason' => 'audit retry'], $admin + ['Idempotency-Key' => 'edge-audit-credit-2']);
        self::assertSame(422, $second['status']);

        $issued = (int) Database::table('gift_credit_ledger')->where('order_id', $orderId)->where('reason', 'issue')->count();
        self::assertSame(1, $issued, 'a rejected second refund-to-credit must not leave a store-credit side effect');
        $credits = (new GiftCreditService())->list(null, 'store_credit');
        self::assertCount(1, $credits);
    }

    public function testCsvDuplicateSkuIsReportedAndOnlyTheFirstRowIsImported(): void
    {
        $admin = $this->loginAs('edge-audit-csv@shop.test', 'admin');
        $csv = "\xEF\xBB\xBF" . "sku,name,slug,status,price_minor,compare_price_minor,stock,description,category,min_tier,allow_backorder,is_preorder\n"
            . "SKU-DUP,First Row,first-row,active,1000,,2,,,,\n"
            . "SKU-DUP,Second Row,second-row,active,2000,,3,,,,\n";

        $response = $this->call(
            'POST',
            '/api/admin/products/import',
            ['csv' => $csv],
            $admin + ['Idempotency-Key' => 'edge-audit-csv-1']
        );
        self::assertSame(200, $response['status']);
        self::assertSame(1, (int) $response['json']['data']['created']);
        self::assertSame(0, (int) $response['json']['data']['updated']);
        self::assertCount(1, $response['json']['data']['errors']);
        self::assertStringContainsString('duplicated in this import', (string) $response['json']['data']['errors'][0]['error']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM products WHERE slug IN ('first-row','second-row')")->fetchColumn());
    }

    private function insertRewrite(string $from, string $target, int $type = 301): void
    {
        Database::table('url_rewrites')->insert([
            'id' => Ids::new(),
            'request_path' => $from,
            'target_path' => $target,
            'redirect_type' => $type,
            'store_id' => 'edge-audit-store',
            'channel_id' => 'web',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, string> $headers */
    private function resolveRedirect(string $path, array $headers): array
    {
        return $this->call('GET', '/api/redirects/resolve', [], $headers, ['path' => $path]);
    }

    public function testRedirectResolveRejectsSchemeRelativeBackslashAndOversizedPaths(): void
    {
        $this->insertRewrite('/edge-scheme-relative', '//evil.example/phish');
        $this->insertRewrite('/edge-backslash', '/pages\\evil');
        $headers = [
            'X-Store-Id' => 'edge-audit-store',
            'X-Channel-Id' => 'web',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];

        foreach (['', '//evil.example/phish', '/edge-scheme-relative', '/edge-backslash', '/' . str_repeat('a', 2048)] as $path) {
            $response = $this->resolveRedirect($path, $headers);
            self::assertSame(200, $response['status'], substr($path, 0, 40));
            self::assertNull($response['json']['data']['target'], substr($path, 0, 40));
        }
    }

    public function testSearchEscapesSpecialCharactersAndKeepsPiiOutOfTheResponse(): void
    {
        $products = new ProductRepository();
        $products->store([
            'id' => 'edge-search-linen', 'name' => 'Linen Shirt', 'slug' => 'edge-search-linen',
            'status' => 'active', 'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD',
        ]);
        $headers = ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'USD'];

        $injection = $this->call('GET', '/api/products', [], $headers, ['search' => "' OR 1=1 --"]);
        self::assertSame(200, $injection['status']);
        self::assertSame([], array_column((array) ($injection['json']['data'] ?? []), 'id'));

        $wildcards = $this->call('GET', '/api/products', [], $headers, ['search' => '%_~[]{}']);
        self::assertSame(200, $wildcards['status']);

        $pii = $this->call('GET', '/api/products', [], $headers, ['search' => 'buyer@example.test']);
        self::assertSame(200, $pii['status']);
        self::assertStringNotContainsString('buyer@example.test', $pii['output']);
    }

    public function testSitemapSettingsRejectOutOfRangePriorityAndUnknownFrequency(): void
    {
        $admin = $this->loginAs('edge-audit-sitemap@shop.test', 'admin');
        $priority = $this->call('PUT', '/api/admin/search-seo/sitemaps', ['products' => ['frequency' => 'daily', 'priority' => 1.5]], $admin);
        self::assertSame(422, $priority['status']);

        $frequency = $this->call('PUT', '/api/admin/search-seo/sitemaps', ['categories' => ['frequency' => 'often', 'priority' => 0.5]], $admin);
        self::assertSame(422, $frequency['status']);

        $valid = $this->call('PUT', '/api/admin/search-seo/sitemaps', ['products' => ['frequency' => 'daily', 'priority' => 0.8]], $admin);
        self::assertSame(200, $valid['status']);
    }
}
