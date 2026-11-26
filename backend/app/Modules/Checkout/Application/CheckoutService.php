<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Application;

use App\Modules\Checkout\Contracts\CheckoutHandler;

final class CheckoutService
{
    public function __construct(private readonly CheckoutHandler $checkout)
    {
    }

    /** @return array<string, mixed> */
    /** @param array<string, string>|null $scope @param mixed $giftCodes string|string[]|null */
    public function place(string $cartId, string $idempotencyKey, ?string $guestEmail = null, ?string $couponCode = null, ?array $scope = null, ?string $paymentProvider = null, ?string $memberCustomerId = null, ?string $giftCode = null, mixed $giftCodes = null, ?string $fulfillmentMethod = null, ?string $pickupPoint = null): array
    {
        return $this->checkout->place($cartId, $idempotencyKey, $guestEmail, $couponCode, $scope, $paymentProvider, $memberCustomerId, $giftCode, $giftCodes, $fulfillmentMethod, $pickupPoint);
    }
}
