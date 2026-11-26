<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CartRepository;
use App\Modules\Cart\Application\CartService;
use App\Modules\Checkout\Application\CheckoutService;
use App\Providers\CommerceServiceProvider;
use Siro\Core\Container;
use Tests\TestCase;

/**
 * HTTP-level idempotency (spec 03§2): POST /api/checkout/place without
 * Idempotency-Key → 422; repeat with same key → same order id.
 * (Route lands with the HTTP layer; service behavior pinned here.)
 */
final class CheckoutIdempotencyTest extends TestCase
{
    public function testServiceRejectsEmptyKey(): void
    {
        (new CommerceServiceProvider())->register();
        $container = Container::getInstance();
        $checkout = $container->make(CheckoutService::class);
        $cartService = $container->make(CartService::class);
        $cart = $cartService->open(null, 'VND');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency-Key is required');
        $checkout->place($cart['id'], '');
    }
}
