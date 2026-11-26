<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Modules\Promotion\Application\FlashSaleService;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Modules\Promotion\Application\PromotionService;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class EdgeCaseTest extends TestCase
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

    private function comboService(array $rules): PromotionService
    {
        $reader = new class($rules) implements PromotionReader {
            public function __construct(private array $rules) {}
            public function findActive(string $code): ?array
            {
                return ['id' => 'c-edge', 'code' => $code, 'state' => 'active', 'rules' => json_encode($this->rules)];
            }
        };
        $usage = new class implements PromotionUsageWriter {
            public function record(string $couponId, string $orderId, int $discountMinor): void {}
            public function reserve(string $couponId, string $reservationKey, int $discountMinor): void {}
        };
        return new PromotionService($reader, $usage);
    }

    // ---- flash edges ----
    private function makeVariant(string $id): void
    {
        (new \App\Repositories\CartRepository())->createVariant(['id' => $id, 'product_id' => 'p-' . $id, 'sku' => strtoupper($id), 'price_minor' => 10000]);
    }

    public function testFlashExpiredDraftFutureInactive(): void
    {
        $this->makeVariant('v-edge-1');
        $svc = new FlashSaleService();
        $mk = function (string $name, string $starts, string $ends, string $state) use ($svc) {
            $s = $svc->createSale(['name' => $name, 'starts_at' => $starts, 'ends_at' => $ends, 'state' => $state]);
            $svc->addItem($s['id'], ['variant_id' => 'v-edge-1', 'sale_price_minor' => 100, 'cap_qty' => 0]);
            return $s;
        };
        $mk('past', date('c', time() - 7200), date('c', time() - 3600), 'active');
        $mk('future', date('c', time() + 3600), date('c', time() + 7200), 'active');
        $mk('draft', date('c', time() - 3600), date('c', time() + 3600), 'draft');
        $this->assertSame([], $svc->activeFor(['v-edge-1']));
    }

    public function testFlashCapZeroUnlimitedAndOversell(): void
    {
        $this->makeVariant('v-edge-2');
        $this->makeVariant('v-edge-3');
        $svc = new FlashSaleService();
        $s = $svc->createSale(['name' => 'unlimited', 'starts_at' => date('c', time() - 60), 'ends_at' => date('c', time() + 3600), 'state' => 'active']);
        $svc->addItem($s['id'], ['variant_id' => 'v-edge-2', 'sale_price_minor' => 100, 'cap_qty' => 0]);
        $svc->recordSale('v-edge-2', 100000);
        $this->assertNotSame([], $svc->activeFor(['v-edge-2']));

        $s2 = $svc->createSale(['name' => 'capped', 'starts_at' => date('c', time() - 60), 'ends_at' => date('c', time() + 3600), 'state' => 'active']);
        $svc->addItem($s2['id'], ['variant_id' => 'v-edge-3', 'sale_price_minor' => 100, 'cap_qty' => 1]);
        $svc->recordSale('v-edge-3', 1);
        $this->expectException(\RuntimeException::class);
        $svc->recordSale('v-edge-3', 1);
    }

    public function testFlashInvalidWindowRejected(): void
    {
        $svc = new FlashSaleService();
        try {
            $svc->createSale(['name' => 'bad', 'starts_at' => date('c', time() + 3600), 'ends_at' => date('c', time() - 3600), 'state' => 'active']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('window', $e->getMessage());
        }
        try {
            $svc->addItem('nope', ['variant_id' => 'v', 'sale_price_minor' => -5]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    // ---- combo edges ----
    public function testComboMaxSetsAndPartialGift(): void
    {
        $svc = $this->comboService(['type' => 'buy_x_get_y', 'buy_variant_ids' => ['b'], 'buy_qty' => 1, 'get_variant_ids' => ['g'], 'get_qty' => 1, 'max_sets' => 1]);
        $lines = [
            ['variant_id' => 'b', 'qty' => 5, 'unit_price_minor' => 1000],
            ['variant_id' => 'g', 'qty' => 5, 'unit_price_minor' => 400],
        ];
        $res = $svc->preview('C', 7000, 'VND', $lines, []);
        $this->assertSame(400, $res['discount_minor']); // capped to 1 set
    }

    public function testComboGiftMoreThanEntitlement(): void
    {
        $svc = $this->comboService(['type' => 'buy_x_get_y', 'buy_variant_ids' => ['b'], 'buy_qty' => 2, 'get_variant_ids' => ['g'], 'get_qty' => 1]);
        $lines = [
            ['variant_id' => 'b', 'qty' => 2, 'unit_price_minor' => 1000],
            ['variant_id' => 'g', 'qty' => 5, 'unit_price_minor' => 300],
        ];
        $res = $svc->preview('C', 3500, 'VND', $lines, []);
        $this->assertSame(300, $res['discount_minor']); // only 1 free, not 5
    }

    public function testComboInvalidLinesRejected(): void
    {
        $svc = $this->comboService(['type' => 'buy_x_get_y', 'buy_variant_ids' => ['b'], 'buy_qty' => 1, 'get_variant_ids' => ['g'], 'get_qty' => 1]);
        $res = $svc->preview('C', 1000, 'VND', [['variant_id' => 'b', 'qty' => 0, 'unit_price_minor' => 1000]], []);
        $this->assertArrayHasKey('error', $res);
        $res2 = $svc->preview('C', 0, 'VND', [], []);
        $this->assertArrayHasKey('error', $res2);
    }

    public function testComboMissingTargetsRejected(): void
    {
        $svc = $this->comboService(['type' => 'buy_x_get_y', 'buy_qty' => 1, 'get_variant_ids' => ['g'], 'get_qty' => 1]);
        $res = $svc->preview('C', 1000, 'VND', [['variant_id' => 'b', 'qty' => 1, 'unit_price_minor' => 1000]], []);
        $this->assertArrayHasKey('error', $res);
    }

    // ---- member edges ----
    public function testMemberHigherPriceNoDiscount(): void
    {
        $this->makeVariant('v-mem-1');
        $svc = new MemberPriceService();
        $svc->setPrice(['variant_id' => 'v-mem-1', 'tier' => 'gold', 'price_minor' => 99999, 'currency' => 'VND']);
        \Siro\Core\Database::table('customer_loyalty_snapshot')->insert([
            'id' => 'snap-1', 'customer_id' => 'cust-1', 'order_id' => 'o-1', 'tier' => 'gold', 'points_earned' => 10, 'applied_at' => date('Y-m-d H:i:s'),
        ]);
        $res = $svc->discountFor('cust-1', [['variant_id' => 'v-mem-1', 'qty' => 1, 'unit_price_minor' => 1000]], 'VND');
        $this->assertSame(0, $res['discount_minor']);
        $this->assertNull($res['tier']);
    }

    public function testMemberCurrencyMismatchSkipped(): void
    {
        $this->makeVariant('v-mem-2');
        $svc = new MemberPriceService();
        $svc->setPrice(['variant_id' => 'v-mem-2', 'tier' => 'silver', 'price_minor' => 100, 'currency' => 'USD']);
        \Siro\Core\Database::table('customer_loyalty_snapshot')->insert([
            'id' => 'snap-2', 'customer_id' => 'cust-2', 'order_id' => 'o-2', 'tier' => 'silver', 'points_earned' => 5, 'applied_at' => date('Y-m-d H:i:s'),
        ]);
        $res = $svc->discountFor('cust-2', [['variant_id' => 'v-mem-2', 'qty' => 1, 'unit_price_minor' => 1000]], 'VND');
        $this->assertSame(0, $res['discount_minor']);
    }

    public function testMemberBadTierRejected(): void
    {
        $svc = new MemberPriceService();
        try {
            $svc->setPrice(['variant_id' => 'v', 'tier' => 'diamond', 'price_minor' => 1]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
        $this->assertNull(MemberPriceService::normalizeTier('DIAMOND '));
        $this->assertSame('gold', MemberPriceService::normalizeTier(' Gold '));
    }

    // ---- cart edges ----
    public function testCartQtyBoundsReturn422(): void
    {
        $carts = new CartRepository();
        $carts->createVariant(['id' => 'v-qty-1', 'product_id' => 'p-qty-1', 'sku' => 'QTY-1', 'price_minor' => 1000]);
        (new InventoryRepository())->setOnHand('v-qty-1', 1000, 'test');
        $cart = $this->call('POST', '/api/carts', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $cartId = $cart['json']['data']['id'];
        $token = $cart['json']['data']['access_token'];
        $h = ['X-Cart-Token' => $token];
        foreach ([0, -1, 100, 1000000] as $qty) {
            $r = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-qty-1', 'qty' => $qty], $h);
            $this->assertSame(422, $r['status'], "qty={$qty}");
        }
        $ok = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-qty-1', 'qty' => 99], $h);
        $this->assertSame(201, $ok['status']);
    }

    public function testBookingRequiresSlot(): void
    {
        $carts = new CartRepository();
        $vid = 'v-bk-' . bin2hex(random_bytes(4));
        $carts->createVariant(['id' => $vid, 'product_id' => 'p-bk-' . bin2hex(random_bytes(4)), 'sku' => 'BK-' . bin2hex(random_bytes(2)), 'price_minor' => 5000]);
        // mark product as booking directly in DB
        $prodId = \App\Models\ProductVariant::query()->where('id', $vid)->first()->toArray()['product_id'];
        \App\Models\Product::create(['id' => $prodId, 'name' => 'BK', 'slug' => 'bk-' . bin2hex(random_bytes(3)), 'type' => 'booking', 'status' => 'active', 'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND']);
        (new InventoryRepository())->setOnHand($vid, 10, 'test');
        $cart = $this->call('POST', '/api/carts', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']);
        $cartId = $cart['json']['data']['id'];
        $token = $cart['json']['data']['access_token'];
        $r = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => $vid, 'qty' => 1], ['X-Cart-Token' => $token]);
        $this->assertSame(422, $r['status']);
    }
}
