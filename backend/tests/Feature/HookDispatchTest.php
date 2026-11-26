<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Order;
use App\Modules\Inventory\Application\InventoryManager;
use App\Modules\Order\Application\OrderManager;
use App\Modules\Platform\Infrastructure\InventoryStoreAdapter;
use App\Modules\Platform\Infrastructure\OrderCommandAdapter;
use App\Repositories\InventoryRepository;
use App\Modules\Shared\Hooks\HookBus;
use App\Modules\Shared\Hooks\HookCatalog;
use App\Modules\Shared\Hooks\HookDecision;
use App\Modules\Shared\Hooks\HookHandler;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use App\Repositories\OrderRepository;
use Tests\TestCase;

final class HookDispatchTest extends TestCase
{
    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        HookBus::resetShared();
        $this->seen = [];
    }

    protected function tearDown(): void
    {
        HookBus::resetShared();
        parent::tearDown();
    }

    private function handler(string $id, int $priority, callable $fn): HookHandler
    {
        return new class($id, $priority, $fn) implements HookHandler {
            public function __construct(private string $id_, private int $priority_, private $fn_) {}
            public function id(): string { return $this->id_; }
            public function priority(): int { return $this->priority_; }
            public function handle(string $hook, array &$payload): HookDecision { return ($this->fn_)($hook, $payload); }
        };
    }

    private function makeOrder(string $state): string
    {
        $id = 'hook-' . bin2hex(random_bytes(6));
        Order::create(['id' => $id, 'number' => 'HK-' . bin2hex(random_bytes(4)), 'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => $state, 'idempotency_key' => 'hook-' . bin2hex(random_bytes(6))]);
        return $id;
    }

    public function testCatalogListsAllTenHooks(): void
    {
        foreach (['pricing.before_quote', 'order.before_transition', 'catalog.product.saved', 'payment.before_capture', 'order.delivered', 'customer.registered', 'checkout.before_place', 'refund.before_issue', 'subscription.before_renew', 'inventory.before_reserve'] as $name) {
            self::assertTrue(HookCatalog::contains($name), $name);
        }
    }

    public function testVetoBlocksOrderTransitionAndPersistsNothing(): void
    {
        HookBus::shared()->register(HookName::OrderBeforeTransition, $this->handler('fraud-hold', 100, function (string $hook, array &$payload): HookDecision {
            return HookDecision::reject('fraud hold');
        }));
        $id = $this->makeOrder('paid');
        $manager = new OrderManager(new OrderCommandAdapter(new OrderRepository()));
        try {
            $manager->transition($id, 'processing', 'admin', '');
            self::fail('veto should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('fraud hold', $e->getMessage());
        }
        $row = Order::query()->where('id', $id)->first();
        self::assertSame('paid', $row->toArray()['state']);
    }

    public function testCrashingHandlerIsIsolatedAndOrderingHolds(): void
    {
        $seen = &$this->seen;
        HookBus::shared()->register(HookName::CheckoutBeforePlace, $this->handler('low', 100, function () use (&$seen): HookDecision {
            $seen[] = 'low';
            return HookDecision::continue();
        }));
        HookBus::shared()->register(HookName::CheckoutBeforePlace, $this->handler('boom', 150, function (): HookDecision {
            throw new \RuntimeException('module exploded');
        }));
        HookBus::shared()->register(HookName::CheckoutBeforePlace, $this->handler('high', 200, function () use (&$seen): HookDecision {
            $seen[] = 'high';
            return HookDecision::continue();
        }));
        $result = Hooks::fire(HookName::CheckoutBeforePlace, ['cart_id' => 'c-1']);
        self::assertSame(['low', 'high'], $this->seen);
        self::assertSame('c-1', $result['cart_id']);
    }

    public function testNotifySwallowsRejectAfterCommit(): void
    {
        HookBus::shared()->register(HookName::OrderDelivered, $this->handler('late-veto', 100, function (): HookDecision {
            return HookDecision::reject('too late');
        }));
        $id = $this->makeOrder('shipped');
        $manager = new OrderManager(new OrderCommandAdapter(new OrderRepository()));
        $updated = $manager->transition($id, 'delivered', 'admin', '');
        self::assertSame('delivered', $updated['state']);
    }

    public function testPricingHookMutatesLines(): void
    {
        HookBus::shared()->register(HookName::PricingBeforeQuote, $this->handler('member-tier', 100, function (string $hook, array &$payload): HookDecision {
            foreach ($payload['lines'] as &$line) $line['unit_price_minor'] = 900;
            return HookDecision::continue();
        }));
        $result = Hooks::fire(HookName::PricingBeforeQuote, ['currency' => 'VND', 'lines' => [['variant_id' => 'v1', 'qty' => 1, 'unit_price_minor' => 1000]]]);
        self::assertSame(900, $result['lines'][0]['unit_price_minor']);
    }

    public function testMissingPayloadKeysFailClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Hooks::fire(HookName::RefundBeforeIssue, ['order_id' => 'o-1']);
    }

    public function testVetoBlocksInventoryReserve(): void
    {
        HookBus::shared()->register(HookName::InventoryBeforeReserve, $this->handler('allocation-policy', 100, function (): HookDecision {
            return HookDecision::reject('source frozen');
        }));
        $manager = new InventoryManager(new InventoryStoreAdapter(new InventoryRepository()));
        try {
            $manager->reserve('v-hook-1', 1, 'cart-1');
            self::fail('veto should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('source frozen', $e->getMessage());
        }
    }

    public function testUnknownHookRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HookBus::shared()->dispatch('commerce.hologram', []);
    }
}
