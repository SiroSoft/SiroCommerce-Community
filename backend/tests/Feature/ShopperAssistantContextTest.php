<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extensions\Siro\ShopperAssistant\ShopperAssistantService;
use App\Modules\Ai\Application\AiSuggestionService;
use App\Modules\Ai\Application\AssistantService;
use App\Modules\Ai\Contracts\AiAuditWriter;
use App\Modules\Ai\Contracts\AiCostLedger;
use App\Modules\Ai\Contracts\AiSuggestionGateway;
use App\Modules\Ai\Contracts\AiSuggestionRequest;
use App\Modules\Ai\Contracts\AiSuggestionResult;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Customer\Contracts\CustomerReader;
use App\Modules\Platform\Infrastructure\CustomerStoreAdapter;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use App\Support\Ids;
use Tests\TestCase;

/**
 * Shopper assistant order context is opt-in and proof-based: anonymous product
 * questions keep working, members see only their own order, guests need the
 * order token, and failures never disclose whether an order exists.
 */
require_once dirname(__DIR__, 2) . '/app/Extensions/Siro/ShopperAssistant/Extension.php';

final class ShopperAssistantContextTest extends TestCase
{
    private function scope(string $store = 'store-shopper', string $channel = 'web'): CommerceScope
    {
        return new CommerceScope($store, $channel, 'en', 'USD', 'tenant-1');
    }

    private function service(CapturingShopperGateway $gateway): ShopperAssistantService
    {
        $costs = new class implements AiCostLedger {
            public function remainingMinor(string $tenantId): int { return 100; }
            public function reserveMinor(string $tenantId, int $amountMinor): bool { return true; }
        };
        $audit = new class implements AiAuditWriter {
            public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void {}
        };

        return new ShopperAssistantService(
            new AssistantService(new AiSuggestionService($gateway, $costs, $audit)),
            new ProductRepository(),
            new OrderRepository(),
            new UserRepository(),
            new CustomerStoreAdapter(),
        );
    }

    /** @return array<string, mixed> */
    private function member(string $email): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'Shopper', 'email' => $email, 'password' => 'secret123', 'role' => 'viewer']);
        $user = (new UserRepository())->findByEmail($email);
        self::assertNotNull($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function customerProfile(string $email): array
    {
        return (new CustomerStoreAdapter())->create(['id' => Ids::new(), 'email' => $email, 'name' => 'Shopper']);
    }

    /** @param array<string, mixed> $overrides */
    private function order(string $id, array $overrides = []): array
    {
        $createdAt = is_string($overrides['created_at'] ?? null) ? $overrides['created_at'] : '2026-11-01 10:00:00';
        $row = (new OrderRepository())->createOrder(array_merge([
            'id' => $id,
            'number' => 'SHOP-' . strtoupper($id),
            'currency' => 'USD',
            'subtotal_minor' => 2500,
            'discount_minor' => 0,
            'shipping_minor' => 0,
            'tax_minor' => 0,
            'grand_total_minor' => 2500,
            'state' => 'processing',
            'idempotency_key' => 'idem-' . $id,
            'store_id' => 'store-shopper',
            'channel_id' => 'web',
            'tenant_id' => 'tenant-1',
        ], $overrides));
        // Order::create ignores created_at (it is not fillable), so stamp the
        // timestamp the same way a real order row carries it.
        \Siro\Core\Database::connection()->prepare('UPDATE orders SET created_at = ? WHERE id = ?')->execute([$createdAt, $id]);

        return $row;
    }

    private function addLine(string $orderId, string $name, int $qty): void
    {
        (new OrderRepository())->addLine([
            'id' => Ids::new(),
            'order_id' => $orderId,
            'variant_id' => 'variant-' . $orderId,
            'sku' => 'SKU-' . $orderId,
            'name' => $name,
            'qty' => $qty,
            'unit_price_minor' => 1250,
        ]);
    }

    /** @param array<string, mixed> $facts @return string */
    private function json(array $facts): string
    {
        return (string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function testAnonymousProductQuestionGetsNoOrderContext(): void
    {
        $gateway = new CapturingShopperGateway();
        $result = $this->service($gateway)->ask($this->scope(), [
            'question' => 'Can you help me choose a size?',
            'product_id' => 'missing-product',
        ]);

        self::assertSame('available', $result['status']);
        self::assertSame([], $result['sources']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['autonomous_mutation']);
        self::assertNotNull($gateway->request);
        self::assertArrayNotHasKey('order', $gateway->request->input['facts']);
        self::assertSame('guest', $gateway->request->actorId);
    }

    public function testSignedInCustomerGetsOnlyTheirOwnOrderFacts(): void
    {
        $user = $this->member('own-order@example.test');
        $customer = $this->customerProfile('own-order@example.test');
        $userId = (int) $user['id'];
        self::assertGreaterThan(0, $userId);

        foreach ([(string) $userId, (string) $customer['id']] as $index => $customerId) {
            $orderId = 'member-order-' . $index;
            $this->order($orderId, ['customer_id' => $customerId, 'guest_email' => 'own-order@example.test']);
            $this->addLine($orderId, 'Linen camp shirt', 2);

            $gateway = new CapturingShopperGateway();
            $result = $this->service($gateway)->ask($this->scope(), [
                'question' => 'What is the status of my order?',
                'order_id' => $orderId,
            ], ['id' => $userId]);

            self::assertSame('available', $result['status']);
            self::assertNotNull($gateway->request);
            $facts = $gateway->request->input['facts'];
            self::assertIsArray($facts);
            self::assertArrayHasKey('order', $facts);
            $order = $facts['order'];
            self::assertIsArray($order);
            self::assertSame('SHOP-' . strtoupper($orderId), $order['number']);
            self::assertSame('processing', $order['state']);
            self::assertSame('USD', $order['currency']);
            self::assertSame(2500, $order['total_minor']);
            self::assertSame(2500, $order['grand_total_minor']);
            self::assertSame('2026-11-01 10:00:00', $order['created_at']);
            self::assertSame([['name' => 'Linen camp shirt', 'qty' => 2]], $order['items']);
            self::assertSame('customer-' . $userId, $gateway->request->actorId);
        }
    }

    public function testGuestGetsOrderFactsWithTheOrderToken(): void
    {
        $this->order('guest-order-ok', [
            'guest_email' => 'guest@example.test',
            'guest_access_token' => 'guest-token-ok',
        ]);
        $this->addLine('guest-order-ok', 'Stoneware mug', 1);

        $gateway = new CapturingShopperGateway();
        $result = $this->service($gateway)->ask($this->scope(), [
            'question' => 'Where is my order?',
            'order_id' => 'guest-order-ok',
            'order_token' => 'guest-token-ok',
        ]);

        self::assertSame('available', $result['status']);
        self::assertNotNull($gateway->request);
        $facts = $gateway->request->input['facts'];
        self::assertIsArray($facts);
        self::assertArrayHasKey('order', $facts);
        $order = $facts['order'];
        self::assertIsArray($order);
        self::assertSame('SHOP-GUEST-ORDER-OK', $order['number']);
        self::assertSame([['name' => 'Stoneware mug', 'qty' => 1]], $order['items']);
        self::assertSame('guest', $gateway->request->actorId);
    }

    public function testMissingInvalidOrCrossScopeTokensAddNoOrderContext(): void
    {
        $this->order('guest-order-secret', [
            'guest_email' => 'guest@example.test',
            'guest_access_token' => 'guest-token-secret',
        ]);
        $this->order('guest-other-scope', [
            'guest_email' => 'guest@example.test',
            'guest_access_token' => 'guest-token-scope',
            'store_id' => 'store-other',
        ]);

        $cases = [
            ['order_id' => 'guest-order-secret'],
            ['order_id' => 'guest-order-secret', 'order_token' => 'wrong-token'],
            ['order_id' => 'guest-order-secret', 'order_token' => ''],
            ['order_id' => 'guest-other-scope', 'order_token' => 'guest-token-scope'],
            ['order_id' => 'does-not-exist', 'order_token' => 'anything'],
        ];
        foreach ($cases as $data) {
            $gateway = new CapturingShopperGateway();
            $result = $this->service($gateway)->ask($this->scope(), ['question' => 'Where is my order?'] + $data);
            self::assertSame('available', $result['status']);
            self::assertNotNull($gateway->request);
            self::assertArrayNotHasKey('order', $gateway->request->input['facts'], (string) json_encode($data));
            self::assertStringNotContainsString('does not exist', (string) ($result['answer'] ?? ''));
        }
    }

    public function testAnotherCustomerCannotReadTheOrder(): void
    {
        $owner = $this->member('owner@example.test');
        $ownerCustomer = $this->customerProfile('owner@example.test');
        $other = $this->member('other@example.test');
        $otherCustomer = $this->customerProfile('other@example.test');
        $this->order('customer-order-private', [
            'customer_id' => (string) $ownerCustomer['id'],
            'guest_email' => 'owner@example.test',
        ]);

        $gateway = new CapturingShopperGateway();
        $result = $this->service($gateway)->ask($this->scope(), [
            'question' => 'What did I buy?',
            'order_id' => 'customer-order-private',
        ], ['id' => (int) $other['id']]);

        self::assertSame('available', $result['status']);
        self::assertNotNull($gateway->request);
        self::assertArrayNotHasKey('order', $gateway->request->input['facts']);
        self::assertNotSame((int) $owner['id'], (int) $other['id']);
        self::assertNotSame((string) $ownerCustomer['id'], (string) $otherCustomer['id']);
    }

    public function testOrderContextAndResponseContainNoPiiOrPaymentData(): void
    {
        $user = $this->member('pii@example.test');
        $customer = $this->customerProfile('pii@example.test');
        $orders = new OrderRepository();
        $orders->createOrder([
            'id' => 'pii-order',
            'number' => 'SHOP-PII',
            'customer_id' => (string) $customer['id'],
            'guest_email' => 'private-guest@example.test',
            'guest_access_token' => 'private-token',
            'currency' => 'USD',
            'subtotal_minor' => 2500,
            'grand_total_minor' => 2500,
            'state' => 'processing',
            'idempotency_key' => 'private-idempotency-key',
            'store_id' => 'store-shopper',
            'channel_id' => 'web',
            'tenant_id' => 'tenant-1',
            'created_at' => '2026-11-01 10:00:00',
        ]);
        $this->addLine('pii-order', 'Linen camp shirt', 1);
        $orders->createPayment([
            'id' => Ids::new(),
            'order_id' => 'pii-order',
            'provider' => 'manual',
            'amount_minor' => 2500,
            'currency' => 'USD',
            'state' => 'captured',
            'idempotency_key' => 'private-payment-key',
            'provider_ref' => 'provider-secret-reference',
        ]);

        $gateway = new CapturingShopperGateway();
        $result = $this->service($gateway)->ask($this->scope(), [
            'question' => 'Explain my order total.',
            'order_id' => 'pii-order',
        ], ['id' => (int) $user['id']]);

        self::assertNotNull($gateway->request);
        $facts = $this->json($gateway->request->input['facts']);
        $response = $this->json($result);
        foreach ([$facts, $response] as $payload) {
            foreach ([
                'private-guest@example.test',
                'private-token',
                'private-idempotency-key',
                'private-payment-key',
                'provider-secret-reference',
                'guest_email',
                'guest_access_token',
                'customer_id',
                'idempotency_key',
                'provider_ref',
                'payment',
                'address',
                'password',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $payload);
            }
        }
    }
}

final class CapturingShopperGateway implements AiSuggestionGateway
{
    public ?AiSuggestionRequest $request = null;

    public function suggest(AiSuggestionRequest $request): AiSuggestionResult
    {
        $this->request = $request;

        return new AiSuggestionResult('available', ['answer' => 'Grounded in the supplied facts.']);
    }
}
