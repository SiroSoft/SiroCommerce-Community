<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

interface RateProvider
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function quote(array $context): array;
}
