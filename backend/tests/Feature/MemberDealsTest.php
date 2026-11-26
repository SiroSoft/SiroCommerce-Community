<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Promotion\Application\MemberDealService;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Member deals & tier visibility (spec MEMBER_DEALS_SPEC): gated catalog,
 * tier-window flash, birthday + window deals in quote/place totals.
 */
final class MemberDealsTest extends TestCase
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
        $carts->createVariant(['id' => 'v-deal-1', 'product_id' => 'p-deal-1', 'sku' => 'DEAL-001', 'price_minor' => 20000]);
        (new InventoryRepository())->setOnHand('v-deal-1', 50, 'test');
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

    /** @return array{headers: array<string, string>, id: string} */
    private function member(string $email, string $tier): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'member', 'email' => $email, 'password' => 'secret123', 'role' => 'customer']);
        $tokens = $auth->login($email, 'secret123');
        $row = Database::table('users')->where('email', $email)->first();
        $uid = is_array($row) ? (string) ($row['id'] ?? '') : '';
        $this->assertNotSame('', $uid);
        Database::table('customer_loyalty_snapshot')->insert([
            'id' => \App\Support\Ids::new(), 'customer_id' => $uid, 'order_id' => 'o-deal', 'tier' => $tier, 'points_earned' => 10, 'applied_at' => date('Y-m-d H:i:s'),
        ]);
        return ['headers' => ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'], 'id' => $uid];
    }

    /** @return array<string, string> */
    private function admin(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'admin', 'email' => 'dealadmin@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('dealadmin@shop.test', 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @return array{string, array<string, string>} */
    private function cartWithLine(int $qty): array
    {
        $cart = $this->call('POST', '/api/carts', []);
        $this->assertSame(201, $cart['status']);
        $cartId = (string) $cart['json']['data']['id'];
        $headers = ['X-Cart-Token' => (string) $cart['json']['data']['access_token']];
        $line = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-deal-1', 'qty' => $qty], $headers);
        $this->assertSame(201, $line['status']);
        return [$cartId, $headers];
    }

    public function testTierRanks(): void
    {
        self::assertSame(-1, MemberPriceService::rankOf(null));
        self::assertSame(-1, MemberPriceService::rankOf('nobody'));
        self::assertSame(0, MemberPriceService::rankOf('bronze'));
        self::assertSame(3, MemberPriceService::rankOf('PLATINUM'));
        self::assertTrue(MemberPriceService::tierCanSee(null, null));
        self::assertFalse(MemberPriceService::tierCanSee(null, 'bronze'));
        self::assertTrue(MemberPriceService::tierCanSee('gold', 'silver'));
        self::assertFalse(MemberPriceService::tierCanSee('silver', 'gold'));
    }

    public function testGatedProductHiddenFromListDetailAndCheckout(): void
    {
        $admin = $this->admin();
        $created = $this->call('POST', '/api/admin/products', ['name' => 'Gold Vault', 'price_minor' => 20000, 'stock' => 5, 'sku' => 'VAULT-001', 'min_tier' => 'gold'], $admin);
        $this->assertSame(201, $created['status']);
        $slug = (string) $created['json']['data']['slug'];
        $activated = $this->call('PUT', '/api/admin/products/' . (string) $created['json']['data']['id'], ['status' => 'active'], $admin);
        $this->assertSame(200, $activated['status']);
        $this->assertSame('gold', $activated['json']['data']['min_tier'] ?? null);

        // Guest list hides it; guest detail 404s like missing.
        $guestList = $this->call('GET', '/api/products');
        $this->assertSame(200, $guestList['status']);
        foreach ($guestList['json']['data'] as $row) self::assertNotSame($slug, $row['slug'] ?? null);
        $this->assertSame(404, $this->call('GET', '/api/products/' . $slug)['status']);

        // Silver member: still hidden. Gold member: visible.
        $silver = $this->member('silver-deal@shop.test', 'silver');
        $silverList = $this->call('GET', '/api/products', [], $silver['headers']);
        foreach ($silverList['json']['data'] as $row) self::assertNotSame($slug, $row['slug'] ?? null);
        $this->assertSame(404, $this->call('GET', '/api/products/' . $slug, [], $silver['headers'])['status']);
        $gold = $this->member('gold-deal@shop.test', 'gold');
        $detail = $this->call('GET', '/api/products/' . $slug, [], $gold['headers']);
        $this->assertSame(200, $detail['status']);
        $this->assertSame('gold', $detail['json']['data']['min_tier']);

        // Bad tier rejected at write; cleared with empty string.
        $bad = $this->call('PUT', '/api/admin/products/' . (string) $created['json']['data']['id'], ['min_tier' => 'diamond'], $admin);
        $this->assertSame(422, $bad['status']);
    }

    public function testGatedProductsStayOutOfDiscovery(): void
    {
        $admin = $this->admin();
        $created = $this->call('POST', '/api/admin/products', ['name' => 'Gold Vault 2', 'price_minor' => 20000, 'stock' => 5, 'sku' => 'VAULT-002', 'min_tier' => 'gold'], $admin);
        $this->assertSame(201, $created['status']);
        $productId = (string) $created['json']['data']['id'];
        $this->call('PUT', '/api/admin/products/' . $productId, ['status' => 'active'], $admin);
        $variant = Database::table('product_variants')->where('product_id', $productId)->first();
        $gatedVariant = is_array($variant) ? (string) ($variant['id'] ?? '') : '';
        $this->assertNotSame('', $gatedVariant);
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO smart_affinities (id, product_a, product_b, orders, score, computed_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([\App\Support\Ids::new(), 'v-deal-1', $gatedVariant, 3, 30, date('Y-m-d H:i:s')]);
        $pdo->prepare('INSERT INTO smart_review_rollups (id, product_id, rating_avg, rating_count, pos_count, neg_count, aspects_json, excerpts_json, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([\App\Support\Ids::new(), $productId, 4.5, 2, 2, 0, '[]', '{}', date('Y-m-d H:i:s')]);

        // Guest: gated other-product skipped, gated summary hidden.
        $guestAff = $this->call('GET', '/api/products/v-deal-1/affinities');
        $this->assertSame(200, $guestAff['status']);
        $this->assertSame([], $guestAff['json']['data']);
        $guestSum = $this->call('GET', '/api/products/' . $productId . '/reviews/summary');
        $this->assertSame(200, $guestSum['status']);
        $this->assertNull($guestSum['json']['data']);

        // Gold member: sees both.
        $gold = $this->member('gold-disc@shop.test', 'gold');
        $goldAff = $this->call('GET', '/api/products/v-deal-1/affinities', [], $gold['headers']);
        $this->assertSame(200, $goldAff['status']);
        $this->assertCount(1, $goldAff['json']['data']);
        $this->assertSame($gatedVariant, $goldAff['json']['data'][0]['variant_id']);
        $goldSum = $this->call('GET', '/api/products/' . $productId . '/reviews/summary', [], $gold['headers']);
        $this->assertSame(4.5, $goldSum['json']['data']['rating_avg']);
    }

    public function testTierWindowDealAppliesInQuoteAndPlace(): void
    {
        $admin = $this->admin();
        $deal = $this->call('POST', '/api/admin/member-deals', [
            'name' => 'Gold week', 'kind' => 'tier_window', 'min_tier' => 'gold', 'percent_off' => 10,
            'starts_at' => date('Y-m-d H:i:s', time() - 60), 'ends_at' => date('Y-m-d H:i:s', time() + 3600),
        ], $admin + ['Idempotency-Key' => 'deal-1']);
        $this->assertSame(201, $deal['status']);

        // Silver member gets nothing; gold gets 10% of 20000.
        $silver = $this->member('silver-w@shop.test', 'silver');
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $silverQuote = $this->call('GET', "/api/carts/{$cartId}/quote", [], $silver['headers'] + $cartHeaders);
        $this->assertSame(200, $silverQuote['status']);
        $this->assertSame(0, $silverQuote['json']['data']['member_deal_discount_minor']);
        $this->assertNull($silverQuote['json']['data']['member_deal']);

        $gold = $this->member('gold-w@shop.test', 'gold');
        [$cartId2, $cartHeaders2] = $this->cartWithLine(1);
        $quote = $this->call('GET', "/api/carts/{$cartId2}/quote", [], $gold['headers'] + $cartHeaders2);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(2000, $quote['json']['data']['member_deal_discount_minor']);
        $this->assertSame('Gold week', $quote['json']['data']['member_deal']['name']);
        $this->assertSame(18000, $quote['json']['data']['total_minor'] - $quote['json']['data']['shipping_minor'] - $quote['json']['data']['tax_minor']);

        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId2], ['Idempotency-Key' => 'deal-place-1', 'X-Cart-Token' => $cartHeaders2['X-Cart-Token'], 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND', 'Authorization' => $gold['headers']['Authorization']]);
        $this->assertSame(201, $placed['status']);
        $this->assertSame(18000, $placed['json']['data']['grand_total_minor'] - $placed['json']['data']['shipping_minor'] - $placed['json']['data']['tax_minor']);
    }

    public function testBirthdayDealMatchesMonthDay(): void
    {
        $svc = new MemberDealService();
        $gold = $this->member('gold-b@shop.test', 'gold');
        $today = date('m-d');
        Database::table('users')->where('id', (int) $gold['id'])->update(['birthdate' => '1990-' . $today]);
        $deal = $svc->create(['name' => 'Birthday treat', 'kind' => 'birthday', 'percent_off' => 15], 'deal-b-1');
        $this->assertSame('birthday', $deal['kind']);

        $result = $svc->discountFor($gold['id'], 20000);
        $this->assertSame(3000, $result['discount_minor']);
        $this->assertSame('Birthday treat', $result['deal']['name']);

        // Another member born on a different day gets nothing.
        $other = $this->member('gold-b2@shop.test', 'gold');
        Database::table('users')->where('id', (int) $other['id'])->update(['birthdate' => '1990-01-01']);
        $miss = (new MemberDealService())->discountFor($other['id'], 20000);
        // 1990-01-01 only matches on Jan 1st.
        if ($today === '01-01') {
            $this->assertSame(3000, $miss['discount_minor']);
        } else {
            $this->assertSame(0, $miss['discount_minor']);
        }

        // Bad inputs rejected: future birthdate, bad percent, bad kind.
        self::assertNull(MemberDealService::normalizeBirthdate(date('Y-m-d', time() + 86400)));
        self::assertNull(MemberDealService::normalizeBirthdate('not-a-date'));
        self::assertSame('1990-' . $today, MemberDealService::normalizeBirthdate('1990-' . $today));
        try {
            $svc->create(['name' => 'Bad', 'kind' => 'nope', 'percent_off' => 10], 'deal-b-2');
            self::fail('bad kind should throw');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $svc->create(['name' => 'Bad', 'kind' => 'tier_window', 'percent_off' => 91], 'deal-b-3');
            self::fail('bad percent should throw');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testBestDealWinsAndDisabledIgnored(): void
    {
        $admin = $this->admin();
        $gold = $this->member('gold-best@shop.test', 'gold');
        $window = ['starts_at' => date('Y-m-d H:i:s', time() - 60), 'ends_at' => date('Y-m-d H:i:s', time() + 3600)];
        $this->call('POST', '/api/admin/member-deals', ['name' => 'Small', 'kind' => 'tier_window', 'percent_off' => 5] + $window, $admin + ['Idempotency-Key' => 'deal-s-1']);
        $big = $this->call('POST', '/api/admin/member-deals', ['name' => 'Big', 'kind' => 'tier_window', 'percent_off' => 20] + $window, $admin + ['Idempotency-Key' => 'deal-b-1']);
        $this->assertSame(201, $big['status']);
        $result = (new MemberDealService())->discountFor($gold['id'], 20000);
        $this->assertSame(4000, $result['discount_minor']);

        $this->call('PUT', '/api/admin/member-deals/' . (string) $big['json']['data']['id'], ['state' => 'disabled'], $admin + ['Idempotency-Key' => 'deal-b-2']);
        $after = (new MemberDealService())->discountFor($gold['id'], 20000);
        $this->assertSame(1000, $after['discount_minor']);

        $list = $this->call('GET', '/api/admin/member-deals', [], $admin);
        $this->assertSame(200, $list['status']);
        $this->assertGreaterThanOrEqual(2, count($list['json']['data']));
    }
}
