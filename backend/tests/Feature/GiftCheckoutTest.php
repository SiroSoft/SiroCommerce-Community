<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Modules\Promotion\Application\GiftCreditService;
use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Gift/store-credit loop end to end: issue → quote preview → place tender →
 * refund-to-credit → wallet. Router + Container autowiring, no App boot.
 */
final class GiftCheckoutTest extends TestCase
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
        $carts->createVariant(['id' => 'v-gift-1', 'product_id' => 'p-gift-1', 'sku' => 'GIFT-001', 'price_minor' => 15000]);
        (new InventoryRepository())->setOnHand('v-gift-1', 10, 'test');
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
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @return array{string, array<string, string>} */
    private function cartWithLine(int $qty): array
    {
        $cart = $this->call('POST', '/api/carts', []);
        $this->assertSame(201, $cart['status']);
        $cartId = (string) $cart['json']['data']['id'];
        $headers = ['X-Cart-Token' => (string) $cart['json']['data']['access_token']];
        $line = $this->call('POST', "/api/carts/{$cartId}/lines", ['variant_id' => 'v-gift-1', 'qty' => $qty], $headers);
        $this->assertSame(201, $line['status']);
        return [$cartId, $headers];
    }

    /** @return array<string, string> */
    private function scope(string $key, string $token): array
    {
        return ['Idempotency-Key' => $key, 'X-Cart-Token' => $token, 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testGiftTenderCoversFullTotal(): void
    {
        (new GiftCreditService())->issueCredit('FULL-50', null, 'gift', 50000, 'VND', 'gc-full-1', null, null);
        [$cartId, $cartHeaders] = $this->cartWithLine(2);

        $preview = $this->call('POST', '/api/gift/preview', ['code' => 'FULL-50', 'subtotal_minor' => 30000, 'currency' => 'VND']);
        $this->assertSame(200, $preview['status']);
        $this->assertSame(30000, $preview['json']['data']['usable_minor']);

        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'gift_code' => 'FULL-50'], $this->scope('gc-place-1', $cartHeaders['X-Cart-Token']));
        $this->assertSame(201, $placed['status']);
        $this->assertSame(0, $placed['json']['data']['grand_total_minor']);

        $left = (new GiftCreditService())->balance('FULL-50', null);
        $this->assertNotNull($left);
        $this->assertSame(20000, (int) $left['balance_minor']);

        // Unknown gift code is a 422, not a silent ignore.
        [$cartId2, $cartHeaders2] = $this->cartWithLine(1);
        $bad = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId2, 'gift_code' => 'NOPE'], $this->scope('gc-place-2', $cartHeaders2['X-Cart-Token']));
        $this->assertSame(422, $bad['status']);
    }

    public function testRefundToCreditReplayAndWallet(): void
    {
        $member = $this->loginAs('member@gift.test', 'customer');
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $placed = $this->call(
            'POST',
            '/api/checkout/place',
            ['cart_id' => $cartId],
            ['Idempotency-Key' => 'gc-place-3', 'X-Cart-Token' => $cartHeaders['X-Cart-Token'], 'X-Store-Id' => 'storefront', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND', 'Authorization' => $member['Authorization']]
        );
        $this->assertSame(201, $placed['status']);
        $orderId = (string) $placed['json']['data']['id'];
        $this->assertSame(15000, $placed['json']['data']['grand_total_minor']);
        Order::find($orderId)?->update(['store_id' => 'default', 'channel_id' => 'default']);

        $admin = $this->loginAs('admin@gift.test', 'admin');
        $paid = $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'paid'], $admin);
        $this->assertSame(200, $paid['status']);

        $key = ['Idempotency-Key' => 'gc-credit-1'];
        $credit = $this->call('POST', "/api/admin/orders/{$orderId}/refund-to-credit", ['reason' => 'oos'], $admin + $key);
        $this->assertSame(200, $credit['status']);
        $this->assertSame('refunded', $credit['json']['data']['order']['state']);
        $creditId = (string) $credit['json']['data']['credit']['id'];
        $this->assertSame(15000, $credit['json']['data']['credit']['balance_minor']);

        // Replay returns the same credit, no duplicate ledger row.
        $replay = $this->call('POST', "/api/admin/orders/{$orderId}/refund-to-credit", ['reason' => 'oos'], $admin + $key);
        $this->assertSame(200, $replay['status']);
        $this->assertSame($creditId, (string) $replay['json']['data']['credit']['id']);

        // Member wallet shows the store credit.
        $wallet = $this->call('GET', '/api/account/credits', [], $member);
        $this->assertSame(200, $wallet['status']);
        $this->assertSame(15000, (int) $wallet['json']['data'][0]['balance_minor']);

        // Nothing left → 422.
        $empty = $this->call('POST', "/api/admin/orders/{$orderId}/refund-to-credit", [], $admin + ['Idempotency-Key' => 'gc-credit-2']);
        $this->assertSame(422, $empty['status']);
    }

    public function testGuestOrderCannotRefundToCredit(): void
    {
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId], $this->scope('gc-place-4', $cartHeaders['X-Cart-Token']));
        $this->assertSame(201, $placed['status']);
        $orderId = (string) $placed['json']['data']['id'];
        Order::find($orderId)?->update(['store_id' => 'default', 'channel_id' => 'default']);

        $admin = $this->loginAs('admin2@gift.test', 'admin');
        $this->call('POST', "/api/admin/orders/{$orderId}/transition", ['to' => 'paid'], $admin);
        $res = $this->call('POST', "/api/admin/orders/{$orderId}/refund-to-credit", [], $admin + ['Idempotency-Key' => 'gc-credit-3']);
        $this->assertSame(422, $res['status']);
    }

    public function testSplitTenderStacksTwoCodesInOrder(): void
    {
        $svc = new GiftCreditService();
        $svc->issueCredit('SPLIT-A', null, 'gift', 20000, 'VND', 'gc-split-a', null, null);
        $svc->issueCredit('SPLIT-B', null, 'gift', 20000, 'VND', 'gc-split-b', null, null);
        [$cartId, $cartHeaders] = $this->cartWithLine(2);

        $quote = $this->call('GET', "/api/carts/{$cartId}/quote", [], $cartHeaders, ['gift' => ['SPLIT-A', 'SPLIT-B']]);
        $this->assertSame(200, $quote['status']);
        $this->assertSame(30000, $quote['json']['data']['subtotal_minor']);
        $this->assertSame(30000, $quote['json']['data']['gift_discount_minor']);
        $this->assertSame(0, $quote['json']['data']['total_minor']);
        $tenders = $quote['json']['data']['gift_tenders'];
        $this->assertSame('SPLIT-A', $tenders[0]['code']);
        $this->assertSame(20000, $tenders[0]['covered_minor']);
        $this->assertSame('SPLIT-B', $tenders[1]['code']);
        $this->assertSame(10000, $tenders[1]['covered_minor']);

        $placed = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'gift_codes' => ['SPLIT-A', 'SPLIT-B']], $this->scope('gc-split-1', $cartHeaders['X-Cart-Token']));
        $this->assertSame(201, $placed['status']);
        $this->assertSame(0, $placed['json']['data']['grand_total_minor']);
        $orderId = (string) $placed['json']['data']['id'];

        $leftA = $svc->balance('SPLIT-A', null);
        $this->assertNotNull($leftA);
        $this->assertSame(0, (int) $leftA['balance_minor']);
        $leftB = $svc->balance('SPLIT-B', null);
        $this->assertNotNull($leftB);
        $this->assertSame(10000, (int) $leftB['balance_minor']);

        // Replay of the same place key returns the same order, no double spend.
        $replay = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'gift_codes' => ['SPLIT-A', 'SPLIT-B']], $this->scope('gc-split-1', $cartHeaders['X-Cart-Token']));
        $this->assertSame(201, $replay['status']);
        $this->assertSame($orderId, (string) $replay['json']['data']['id']);
        $stillB = $svc->balance('SPLIT-B', null);
        $this->assertNotNull($stillB);
        $this->assertSame(10000, (int) $stillB['balance_minor']);
    }

    public function testSplitTenderFailsClosed(): void
    {
        $svc = new GiftCreditService();
        $svc->issueCredit('SPLIT-GOOD', null, 'gift', 20000, 'VND', 'gc-split-good', null, null);

        // One bad code aborts the whole tender; the good code is untouched.
        [$cartId, $cartHeaders] = $this->cartWithLine(1);
        $bad = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId, 'gift_codes' => ['SPLIT-GOOD', 'NOPE']], $this->scope('gc-split-2', $cartHeaders['X-Cart-Token']));
        $this->assertSame(422, $bad['status']);
        $untouched = $svc->balance('SPLIT-GOOD', null);
        $this->assertNotNull($untouched);
        $this->assertSame(20000, (int) $untouched['balance_minor']);

        // gift_code + gift_codes together → 422, nothing redeemed.
        [$cartId2, $cartHeaders2] = $this->cartWithLine(1);
        $both = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId2, 'gift_code' => 'SPLIT-GOOD', 'gift_codes' => ['SPLIT-GOOD']], $this->scope('gc-split-3', $cartHeaders2['X-Cart-Token']));
        $this->assertSame(422, $both['status']);

        // Duplicate codes → 422.
        [$cartId3, $cartHeaders3] = $this->cartWithLine(1);
        $dupe = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId3, 'gift_codes' => ['split-good', 'SPLIT-GOOD']], $this->scope('gc-split-4', $cartHeaders3['X-Cart-Token']));
        $this->assertSame(422, $dupe['status']);

        // Over the code limit → 422.
        [$cartId4, $cartHeaders4] = $this->cartWithLine(1);
        $many = $this->call('POST', '/api/checkout/place', ['cart_id' => $cartId4, 'gift_codes' => ['A', 'B', 'C', 'D', 'E', 'F']], $this->scope('gc-split-5', $cartHeaders4['X-Cart-Token']));
        $this->assertSame(422, $many['status']);
    }
}
