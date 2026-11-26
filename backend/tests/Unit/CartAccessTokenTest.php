<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cart\Application\CartService;
use App\Modules\Cart\Contracts\CartCommandPort;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Checkout\Contracts\PromotionPort;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Tax\Contracts\TaxProvider;
use Tests\TestCase;

final class CartAccessTokenTest extends TestCase
{
    public function testOpenReturnsBearerButPersistsOnlyDigestAndOwnershipIsBoundToCart(): void
    {
        $created = [];
        $port = new class ($created) implements CartCommandPort {
            /** @param array<string, mixed> $created */
            public function __construct(private array &$created) {}
            public function create(array $data): array { $this->created = $data; return $data; }
            public function find(string $id): ?array { return $id === 'cart-1' ? $this->created : null; }
            public function lines(string $id): array { return []; }
            public function price(string $variantId): ?int { return null; }
            public function resolveVariant(string $id): ?string { return $id; }
            public function accessTokenIsValid(string $id, string $token): bool
            {
                $hash = $this->created['access_token_hash'] ?? '';
                return $id === 'cart-1' && is_string($hash) && hash_equals($hash, hash('sha256', $token));
            }
            public function addLine(string $cartId, string $variantId, int $qty, int $price, array $extra = []): array { return []; }
            public function setLineQuantity(string $cartId, string $lineId, int $quantity): void {}
            public function removeLine(string $cartId, string $lineId): void {}
            public function primaryImages(array $productIds): array { return []; }
        };

        // PricingEngine is a final orchestration class; construct its interface dependencies
        // instead of asking PHPUnit to double a concrete implementation.
        $shipping = $this->createMock(RateProvider::class);
        $shipping->method('quote')->willReturn([['amount_minor' => 0, 'currency' => 'USD']]);
        $tax = $this->createMock(TaxProvider::class);
        $tax->method('calculate')->willReturn(['total_minor' => 0, 'lines' => [], 'rules' => [], 'currency' => 'USD']);
        $pricing = new PricingEngine($tax, $shipping);
        $service = new CartService($port, $pricing, $this->createMock(PromotionPort::class));
        $cart = $service->open();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cart['access_token']);
        self::assertArrayNotHasKey('access_token_hash', $cart);
        self::assertSame(hash('sha256', $cart['access_token']), $created['access_token_hash']);
        self::assertTrue($service->owns('cart-1', $cart['access_token']));
        self::assertFalse($service->owns('cart-2', $cart['access_token']));
        self::assertFalse($service->owns('cart-1', str_repeat('0', 64)));
    }
}
