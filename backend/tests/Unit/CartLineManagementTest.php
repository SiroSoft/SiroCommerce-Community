<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cart\Application\CartService;
use App\Modules\Cart\Contracts\CartCommandPort;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Checkout\Contracts\PromotionPort;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Tax\Contracts\TaxProvider;
use PHPUnit\Framework\TestCase;

final class CartLineManagementTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $lines = [];
    private string $token = 'token-1';

    private function service(): CartService
    {
        $lines = &$this->lines;
        $token = $this->token;
        $port = new class ($lines, $token) implements CartCommandPort {
            /** @param array<string, array<string, mixed>> $lines */
            public function __construct(private array &$lines, private string $token) {}
            public function create(array $data): array { return $data; }
            public function find(string $id): ?array { return $id === 'cart-1' ? ['id' => 'cart-1', 'currency' => 'USD', 'state' => 'open'] : null; }
            public function lines(string $id): array { return $id === 'cart-1' ? array_values($this->lines) : []; }
            public function price(string $variantId): ?int { return 1000; }
            public function resolveVariant(string $id): ?string { return $id; }
            public function accessTokenIsValid(string $id, string $token): bool { return $id === 'cart-1' && hash_equals($this->token, $token); }
            public function addLine(string $cartId, string $variantId, int $qty, int $price, array $extra = []): array { return []; }
            public function setLineQuantity(string $cartId, string $lineId, int $quantity): void
            {
                if (!isset($this->lines[$lineId]) || $this->lines[$lineId]['cart_id'] !== $cartId) throw new \RuntimeException('Cart line not found');
                $this->lines[$lineId]['qty'] = $quantity;
            }
            public function removeLine(string $cartId, string $lineId): void
            {
                if (!isset($this->lines[$lineId]) || $this->lines[$lineId]['cart_id'] !== $cartId) throw new \RuntimeException('Cart line not found');
                unset($this->lines[$lineId]);
            }
            public function primaryImages(array $productIds): array { return []; }
        };
        $shipping = $this->createMock(RateProvider::class);
        $shipping->method('quote')->willReturn([['amount_minor' => 0, 'currency' => 'USD']]);
        $tax = $this->createMock(TaxProvider::class);
        $tax->method('calculate')->willReturn(['total_minor' => 0, 'lines' => [], 'rules' => [], 'currency' => 'USD']);
        return new CartService($port, new PricingEngine($tax, $shipping), $this->createMock(PromotionPort::class));
    }

    public function testSetLineQuantityUpdatesQtyAndRejectsOutOfRange(): void
    {
        $this->lines = ['line-1' => ['id' => 'line-1', 'cart_id' => 'cart-1', 'variant_id' => 'v-1', 'product_id' => 'p-1', 'name' => 'Shirt', 'qty' => 1, 'unit_price_minor' => 1000]];
        $service = $this->service();
        $cart = $service->setLineQuantity('cart-1', $this->token, 'line-1', 3);
        self::assertSame(3, $cart['lines'][0]['qty']);
        self::assertSame(3000, $cart['subtotal']);

        $this->expectException(\InvalidArgumentException::class);
        $service->setLineQuantity('cart-1', $this->token, 'line-1', 0);
    }

    public function testSetLineQuantityRejectsUnknownCartAndLine(): void
    {
        $service = $this->service();
        $this->expectException(\RuntimeException::class);
        $service->setLineQuantity('cart-1', 'wrong-token', 'line-1', 2);
    }

    public function testRemoveLineDropsTheLineAndRejectsUnknownLines(): void
    {
        $this->lines = ['line-1' => ['id' => 'line-1', 'cart_id' => 'cart-1', 'variant_id' => 'v-1', 'product_id' => 'p-1', 'name' => 'Shirt', 'qty' => 2, 'unit_price_minor' => 1000]];
        $service = $this->service();
        $cart = $service->removeLine('cart-1', $this->token, 'line-1');
        self::assertSame([], $cart['lines']);
        self::assertSame(0, $cart['subtotal']);

        $this->expectException(\RuntimeException::class);
        $service->removeLine('cart-1', $this->token, 'line-1');
    }
}