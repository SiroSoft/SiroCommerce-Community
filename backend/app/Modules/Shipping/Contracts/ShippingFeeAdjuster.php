<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

interface ShippingFeeAdjuster
{
    /**
     * @param array<string, mixed> $scope Store/channel metadata for the quote.
     * @return int Additional shipping fee in minor units; 0 means no change.
     */
    public function fee(array $scope, int $subtotalMinor, string $method): int;
}
