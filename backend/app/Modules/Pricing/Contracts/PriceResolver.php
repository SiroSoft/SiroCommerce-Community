<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

interface PriceResolver
{
    /** @return array{unit_price_minor: int, currency: string, source: string}|null */
    public function resolve(string $variantId, ?string $customerGroupId = null): ?array;
}
