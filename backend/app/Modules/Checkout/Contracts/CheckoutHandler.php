<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Contracts;

interface CheckoutHandler
{
    /** @return array<string, mixed> */
    /** @param array<string, string>|null $scope @param mixed $giftCodes string|string[]|null */
    public function place(string $cartId, string $idempotencyKey, ?string $guestEmail, ?string $couponCode, ?array $scope = null, ?string $paymentProvider = null, ?string $memberCustomerId = null, ?string $giftCode = null, mixed $giftCodes = null, ?string $fulfillmentMethod = null, ?string $pickupPoint = null): array;
}
