<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\PromotionRule;
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
 * Promotion rules end to end (spec PROMOTION_RULES_SPEC): admin CRUD, best
 * single rule, catalog scope, quote/place parity, usage increment, replay.
 */
final class PromotionRulesTest extends TestCase
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

        Category::create(['id' => 'cat-skin', 'name' => 'Skincare', 'slug' => 'skincare', 'sort' => 0, 'is_active' => 1]);
        Category::create(['id' => 'cat-shoes', 'name' => 'Shoes', 'slug' => 'shoes', 'sort' => 0, 'is_active' => 1]);
        Product::create(['id' => 'p-skin', 'name' => 'Serum', 'slug' => 'serum', 'status' => 'active', 'category_id' => 'cat-skin']);
        Product::create(['id' => 'p-shoes', 'name' => 'Runner', 'slug' => 'runner', 'status' => 'active', 'category_id' => 'cat-shoes']);

        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-skin', 'product_id' => 'p-skin', 'sku' => 'SKIN-001', 'price_minor' => 25000]);
        $carts->createVariant(['id' => 'v-shoes', 'product_id' => 'p-shoes', 'sku' => 'SHOE-001', 'price_minor' => 40000]);
        $inventory = new InventoryRepository();
        $inventory->setOnHand('v-skin', 20, 'test');
        $inventory->setOnHand('v-shoes', 20, 'test');
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
        $auth->register(['name' => 'admin', 'email' => 'rules-admin@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('rules-admin@shop.test', 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @return array{0: string, 1: string} */
    private function cartWith(string $variantId, int $qty): array
    {
        $cart = $this->call('POST', '/api/carts', []);
        $this->assertSame(201, $cart['status']);
        $cartId = (string) $cart['json']['data']['id'];
        $token = (string) $cart['json']['data']['access_token'];
        $line = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => $variantId, 'qty' => $qty], ['X-Cart-Token' => $token]);
        $this->assertSame(201, $line['status']);
        return [$cartId, $token];
    }

    /** @param array<string, string> $headers @param array<string, mixed> $body @return array<string, mixed> */
    private function createRule(array $headers, array $body): array
    {
        $created = $this->call('POST', '/api/admin/promotion-rules', $body, $headers);
        $this->assertSame(201, $created['status'], json_encode($created['json']));
        /** @var array<string, mixed> $row */
        $row = $created['json']['data'];
        return $row;
    }

    public function testAdminCrudValidation(): void
    {
        $headers = $this->admin();
        $base = ['name' => 'Ten off', 'kind' => 'cart_percent', 'value' => 10, 'category_slugs' => ['skincare']];
        $this->assertSame(422, $this->call('POST', '/api/admin/promotion-rules', ['name' => 'Bad', 'kind' => 'cart_percent', 'value' => 91], $headers)['status']);
        $this->assertSame(422, $this->call('POST', '/api/admin/promotion-rules', ['name' => 'Bad', 'kind' => 'cart_fixed', 'value' => 0], $headers)['status']);
        $this->assertSame(422, $this->call('POST', '/api/admin/promotion-rules', ['name' => 'Bad', 'kind' => 'cart_fixed', 'value' => 100, 'starts_at' => '2026-02-01 00:00:00', 'ends_at' => '2026-01-01 00:00:00'], $headers)['status']);
        $this->assertSame(422, $this->call('POST', '/api/admin/promotion-rules', ['name' => 'Bad', 'kind' => 'catalog_percent', 'value' => 10, 'category_slugs' => ['missing']], $headers)['status']);
        $this->assertSame(422, $this->call('POST', '/api/admin/promotion-rules', ['name' => 'Bad', 'kind' => 'cart_fixed', 'value' => 100, 'max_uses' => 0], $headers)['status']);

        $rule = $this->createRule($headers, $base);
        $this->assertSame('active', $rule['state']);
        $this->assertSame(['skincare'], $rule['category_slugs']);

        $list = $this->call('GET', '/api/admin/promotion-rules', [], $headers);
        $this->assertSame(200, $list['status']);
        $this->assertCount(1, $list['json']['data']);

        $updated = $this->call('PUT', '/api/admin/promotion-rules/' . (string) $rule['id'], ['state' => 'disabled'], $headers);
        $this->assertSame(200, $updated['status']);
        $this->assertSame('disabled', $updated['json']['data']['state']);

        $deleted = $this->call('DELETE', '/api/admin/promotion-rules/' . (string) $rule['id'], [], $headers);
        $this->assertSame(200, $deleted['status']);
        $this->assertSame([], $this->call('GET', '/api/admin/promotion-rules', [], $headers)['json']['data']);
    }

    public function testQuoteAndPlaceApplySameRuleAndIncrementOnce(): void
    {
        $headers = $this->admin();
        $rule = $this->createRule($headers, ['name' => 'Ten off', 'kind' => 'cart_percent', 'value' => 10]);
        [$cartId, $token] = $this->cartWith('v-skin', 2);

        $quote = $this->call('GET', "/api/carts/{$cartId}/quote", [], ['X-Cart-Token' => $token]);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(5000, $quote['json']['data']['promotion_discount']);
        $this->assertSame((string) $rule['id'], (string) $quote['json']['data']['promotion_rule']['id']);

        $place = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], [
            'Idempotency-Key' => 'rules-place-1',
            'X-Cart-Token' => $token,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ]);
        $this->assertSame(201, $place['status'], json_encode($place['json']));
        $orderId = (string) $place['json']['data']['id'];
        $this->assertSame(45000, $place['json']['data']['grand_total_minor']);
        $snapshot = json_decode((string) $place['json']['data']['promotion_rule_json'], true);
        $this->assertSame((string) $rule['id'], (string) $snapshot['id']);
        $this->assertSame(5000, (int) $snapshot['discount']);
        $this->assertSame(1, (int) PromotionRule::query()->where('id', (string) $rule['id'])->first()?->getAttribute('uses'));

        $replay = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], [
            'Idempotency-Key' => 'rules-place-1',
            'X-Cart-Token' => $token,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ]);
        $this->assertSame(201, $replay['status']);
        $this->assertSame($orderId, (string) $replay['json']['data']['id']);
        $this->assertSame(1, (int) PromotionRule::query()->where('id', (string) $rule['id'])->first()?->getAttribute('uses'));
    }

    public function testCatalogRuleScopesToMatchingCategoryLine(): void
    {
        $headers = $this->admin();
        $rule = $this->createRule($headers, ['name' => 'Skincare 20', 'kind' => 'catalog_percent', 'value' => 20, 'category_slugs' => ['skincare']]);

        $cart = $this->call('POST', '/api/carts', []);
        $cartId = (string) $cart['json']['data']['id'];
        $token = (string) $cart['json']['data']['access_token'];
        $this->assertSame(201, $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-skin', 'qty' => 2], ['X-Cart-Token' => $token])['status']);
        $this->assertSame(201, $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-shoes', 'qty' => 1], ['X-Cart-Token' => $token])['status']);

        $quote = $this->call('GET', "/api/carts/{$cartId}/quote", [], ['X-Cart-Token' => $token]);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(10000, $quote['json']['data']['promotion_discount']);
        $this->assertSame((string) $rule['id'], (string) $quote['json']['data']['promotion_rule']['id']);
        $this->assertSame(80000, $quote['json']['data']['total_minor']);
    }

    public function testDisabledRuleIsIgnored(): void
    {
        $headers = $this->admin();
        $this->createRule($headers, ['name' => 'Off', 'kind' => 'cart_fixed', 'value' => 5000, 'state' => 'disabled']);
        [$cartId, $token] = $this->cartWith('v-skin', 1);
        $quote = $this->call('GET', "/api/carts/{$cartId}/quote", [], ['X-Cart-Token' => $token]);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(0, $quote['json']['data']['promotion_discount']);
        $this->assertNull($quote['json']['data']['promotion_rule']);
    }
}
