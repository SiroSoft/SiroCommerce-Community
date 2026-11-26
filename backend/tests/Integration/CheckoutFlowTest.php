<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\CartRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Modules\Cart\Application\CartService;
use App\Modules\Checkout\Application\CheckoutService;
use App\Modules\Inventory\Application\InventoryManager;
use App\Modules\Inventory\Contracts\InventoryReader;
use App\Providers\CommerceServiceProvider;
use Siro\Core\Container;
use Tests\TestCase;

/**
 * Phase 1 exit: real end-to-end purchase works reliably.
 * AC-A03/F08: duplicate place → ONE order, same id back.
 * AC-D01: order lines snapshot prices (catalog change cannot rewrite).
 */
final class CheckoutFlowTest extends TestCase
{
    /** @return array{checkout: CheckoutService, carts: CartService, orders: OrderRepository, inventory: InventoryManager, repos: CartRepository} */
    private function services(): array
    {
        (new CommerceServiceProvider())->register();
        $container = Container::getInstance();
        $carts = new CartRepository();
        $inventory = $container->make(InventoryManager::class);
        $checkout = $container->make(CheckoutService::class);
        $cartService = $container->make(CartService::class);
        return [
            'checkout' => $checkout,
            'carts' => $cartService,
            'orders' => new OrderRepository(),
            'inventory' => $inventory,
            'repos' => $carts,
        ];
    }

    public function testEndToEndPurchase(): void
    {
        $s = $this->services();
        $variant = $s['repos']->createVariant([
            'id' => 'v-e2e-1',
            'product_id' => 'p-e2e-1',
            'sku' => 'E2E-001',
            'price_minor' => 25000,
        ]);
        (new InventoryRepository())->setOnHand($variant['id'], 10, 'test');

        $cart = $s['carts']->open(null, 'VND');
        $s['carts']->addLine($cart['id'], $variant['id'], 2);

        $order = $s['checkout']->place($cart['id'], 'key-e2e-1', 'guest@example.com');
        $this->assertSame('awaiting_payment', $order['state']);
        $this->assertSame(50000, $order['grand_total_minor']);
        $this->assertSame('guest@example.com', $order['guest_email']);

        $lines = $s['orders']->lines($order['id']);
        $this->assertCount(1, $lines);
        $this->assertSame(25000, $lines[0]['unit_price_minor']);

        $transitions = $s['orders']->transitions($order['id']);
        $this->assertCount(1, $transitions);
        $this->assertSame('pending', $transitions[0]['from_state']);
        $this->assertSame('awaiting_payment', $transitions[0]['to_state']);

        $this->assertSame(8, $s['inventory']->available($variant['id']));
    }

    public function testDuplicatePlaceReturnsOriginal(): void
    {
        $s = $this->services();
        $variant = $s['repos']->createVariant([
            'id' => 'v-e2e-2',
            'product_id' => 'p-e2e-2',
            'sku' => 'E2E-002',
            'price_minor' => 10000,
        ]);
        (new InventoryRepository())->setOnHand($variant['id'], 10, 'test');
        $cart = $s['carts']->open(null, 'VND');
        $s['carts']->addLine($cart['id'], $variant['id'], 1);

        $first = $s['checkout']->place($cart['id'], 'key-dup-1');
        $second = $s['checkout']->place($cart['id'], 'key-dup-1');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(9, $s['inventory']->available($variant['id']));
    }

    public function testPlaceRequiresIdempotencyKey(): void
    {
        $s = $this->services();
        $cart = $s['carts']->open(null, 'VND');
        $this->expectException(\InvalidArgumentException::class);
        $s['checkout']->place($cart['id'], '');
    }

    public function testOrderSnapshotImmuneToPriceChange(): void
    {
        $s = $this->services();
        $variant = $s['repos']->createVariant([
            'id' => 'v-e2e-3',
            'product_id' => 'p-e2e-3',
            'sku' => 'E2E-003',
            'price_minor' => 7000,
        ]);
        (new InventoryRepository())->setOnHand($variant['id'], 10, 'test');
        $cart = $s['carts']->open(null, 'VND');
        $s['carts']->addLine($cart['id'], $variant['id'], 1);
        $order = $s['checkout']->place($cart['id'], 'key-snap-1');

        $row = \App\Models\ProductVariant::find($variant['id']);
        $row->update(['price_minor' => 99999]);

        $lines = $s['orders']->lines($order['id']);
        $this->assertSame(7000, $lines[0]['unit_price_minor']);
        $refreshed = $s['orders']->find($order['id']);
        $this->assertSame(7000, $refreshed['grand_total_minor']);
    }
}
