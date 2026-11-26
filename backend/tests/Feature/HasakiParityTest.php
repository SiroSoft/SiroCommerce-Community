<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Promotion\Application\FlashSaleService;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Modules\Promotion\Application\PromotionService;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class HasakiParityTest extends TestCase
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

    /** @return array<string, string> */
    private function adminHeaders(string $role = 'admin'): array
    {
        $email = 'flash-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter(new \App\Repositories\UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testFlashSaleCrudAndCap(): void
    {
        $headers = $this->adminHeaders();
        (new \App\Repositories\CartRepository())->createVariant(['id' => 'v-flash-1', 'product_id' => 'p-flash-1', 'sku' => 'FLASH-1', 'price_minor' => 20000]);
        $create = $this->call('POST', '/api/admin/flash-sales', [
            'name' => 'Gio vang',
            'starts_at' => date('c', time() - 3600),
            'ends_at' => date('c', time() + 3600),
            'state' => 'active',
        ], array_merge($headers, ['Idempotency-Key' => 'flash-' . bin2hex(random_bytes(4))]));
        $this->assertSame(201, $create['status']);
        $saleId = $create['json']['data']['id'];
        $item = $this->call('POST', "/api/admin/flash-sales/{$saleId}/items", [
            'variant_id' => 'v-flash-1', 'sale_price_minor' => 5000, 'cap_qty' => 2,
        ], array_merge($headers, ['Idempotency-Key' => 'flash-item-' . bin2hex(random_bytes(4))]));
        $this->assertSame(201, $item['status']);
        // duplicate variant in same sale -> 409
        $dup = $this->call('POST', "/api/admin/flash-sales/{$saleId}/items", [
            'variant_id' => 'v-flash-1', 'sale_price_minor' => 4000,
        ], array_merge($headers, ['Idempotency-Key' => 'flash-item-' . bin2hex(random_bytes(4))]));
        $this->assertSame(409, $dup['status']);
        // service-level: active deal found, cap enforced
        $svc = new FlashSaleService();
        $deals = $svc->activeFor(['v-flash-1']);
        $this->assertSame(5000, $deals['v-flash-1']['sale_price_minor']);
        $svc->recordSale('v-flash-1', 2);
        $this->assertSame([], $svc->activeFor(['v-flash-1']));
        $this->expectException(\RuntimeException::class);
        $svc->recordSale('v-flash-1', 1);
    }

    public function testComboBuyXGetY(): void
    {
        $reader = new class implements PromotionReader {
            public function findActive(string $code): ?array
            {
                return ['id' => 'c-combo', 'code' => $code, 'state' => 'active', 'rules' => json_encode([
                    'type' => 'buy_x_get_y', 'buy_variant_ids' => ['v-buy'], 'buy_qty' => 1,
                    'get_variant_ids' => ['v-gift'], 'get_qty' => 1,
                ])];
            }
        };
        $usage = new class implements PromotionUsageWriter {
            public function record(string $couponId, string $orderId, int $discountMinor): void {}
            public function reserve(string $couponId, string $reservationKey, int $discountMinor): void {}
        };
        $svc = new PromotionService($reader, $usage);
        $lines = [
            ['variant_id' => 'v-buy', 'product_id' => 'p1', 'qty' => 2, 'unit_price_minor' => 10000],
            ['variant_id' => 'v-gift', 'product_id' => 'p2', 'qty' => 2, 'unit_price_minor' => 5000],
        ];
        $res = $svc->preview('COMBO', 30000, 'VND', $lines, []);
        $this->assertSame(10000, $res['discount_minor']);
        // gift missing -> error
        $res2 = $svc->preview('COMBO', 20000, 'VND', [['variant_id' => 'v-buy', 'product_id' => 'p1', 'qty' => 1, 'unit_price_minor' => 10000]], []);
        $this->assertArrayHasKey('error', $res2);
    }

    public function testInventorySources(): void
    {
        $headers = $this->adminHeaders();
        $created = $this->call('POST', '/api/admin/products', [
            'name' => 'Src Shirt', 'price_minor' => 1000, 'stock' => 10, 'sku' => 'SRC-' . bin2hex(random_bytes(2)),
        ], $headers);
        $this->assertSame(201, $created['status']);
        $variants = $created['json']['data']['variants'] ?? [];
        $variantId = (string) ($variants[0]['id'] ?? '');
        $this->assertNotSame('', $variantId);
        $inv = new \App\Repositories\InventoryRepository();
        $inv->setOnHand($variantId, 3, 'test', 'store-hcm-01', 'src-b-' . bin2hex(random_bytes(4)));
        $res = $this->call('GET', "/api/admin/inventory/{$variantId}/sources", [], $headers);
        $this->assertSame(200, $res['status']);
        $rows = $res['json']['data'] ?? [];
        $bySource = [];
        foreach ($rows as $r) $bySource[$r['source_id']] = $r['available'];
        $this->assertSame(10, $bySource['default']);
        $this->assertSame(3, $bySource['store-hcm-01']);
    }

    public function testMemberPriceValidation(): void
    {
        $headers = $this->adminHeaders();
        (new \App\Repositories\CartRepository())->createVariant(['id' => 'v-m1', 'product_id' => 'p-m1', 'sku' => 'M-1', 'price_minor' => 10000]);
        $ghost = $this->call('POST', '/api/admin/member-prices', [
            'variant_id' => 'no-such-variant', 'tier' => 'gold', 'price_minor' => 1000,
        ], array_merge($headers, ['Idempotency-Key' => 'member-' . bin2hex(random_bytes(4))]));
        $this->assertSame(422, $ghost['status']);
        $bad = $this->call('POST', '/api/admin/member-prices', [
            'variant_id' => 'v-m1', 'tier' => 'diamond', 'price_minor' => 1000,
        ], array_merge($headers, ['Idempotency-Key' => 'member-' . bin2hex(random_bytes(4))]));
        $this->assertSame(422, $bad['status']);
        $ok = $this->call('POST', '/api/admin/member-prices', [
            'variant_id' => 'v-m1', 'tier' => 'gold', 'price_minor' => 8000, 'currency' => 'VND',
        ], array_merge($headers, ['Idempotency-Key' => 'member-' . bin2hex(random_bytes(4))]));
        $this->assertSame(201, $ok['status']);
        $svc = new MemberPriceService();
        // no snapshot -> no discount
        $res = $svc->discountFor('cust-none', [['variant_id' => 'v-m1', 'qty' => 1, 'unit_price_minor' => 10000]], 'VND');
        $this->assertSame(0, $res['discount_minor']);
    }
}
