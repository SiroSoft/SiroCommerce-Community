<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

interface ShippingProviderRegistry
{
    public function carrier(string $provider): Carrier;
    public function rates(string $provider): RateProvider;
}
