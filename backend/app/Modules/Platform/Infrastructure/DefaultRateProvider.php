<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Shipping\Contracts\RateProvider;

final class DefaultRateProvider implements RateProvider
{
    /** @param array<string, mixed> $context @return array<int, array<string, mixed>> */
    public function quote(array $context): array
    {
        $nested = is_array($context['context'] ?? null) ? $context['context'] : $context;
        $scope = is_array($nested['scope'] ?? null) ? $nested['scope'] : [];
        $currency = is_string($scope['currency'] ?? null) ? strtoupper($scope['currency']) : 'VND';
        $destination = is_array($nested['destination'] ?? null) ? $nested['destination'] : [];
        $origin = is_array($nested['origin'] ?? null) ? $nested['origin'] : ['country' => 'VN'];
        $evidence = [];
        if ($destination !== []) $evidence = \App\Modules\Commerce\Domain\RegionalRules::evidence($origin, $destination);
        return [['code' => 'default', 'name' => 'Standard', 'amount_minor' => 0, 'currency' => $currency, 'evidence' => $evidence]];
    }
}
