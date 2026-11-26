<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Shipping\Contracts\Carrier;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Modules\Shipping\Contracts\ShippingProviderRegistry;
use App\Modules\Shipping\Infrastructure\GhnCarrier;
use App\Modules\Shipping\Infrastructure\ViettelPostCarrier;

final class ConfiguredShippingProviderRegistry implements ShippingProviderRegistry
{
    /** @var array<string, Carrier&RateProvider> */
    private array $carriers;

    public function __construct(
        DhlCarrier $dhl,
        ?GhnCarrier $ghn = null,
        ?ViettelPostCarrier $viettelPost = null,
    ) {
        $this->carriers = ['dhl' => $dhl];
        if ($ghn !== null) $this->carriers['ghn'] = $ghn;
        if ($viettelPost !== null) $this->carriers['viettelpost'] = $viettelPost;
    }

    public function carrier(string $provider): Carrier
    {
        return $this->resolve($provider);
    }

    public function rates(string $provider): RateProvider
    {
        return $this->resolve($provider);
    }

    private function resolve(string $provider): Carrier&RateProvider
    {
        $canonical = strtolower(trim($provider));
        if (!isset($this->carriers[$canonical])) {
            throw new \InvalidArgumentException('Shipping provider is not configured: ' . $canonical);
        }
        return $this->carriers[$canonical];
    }
}
