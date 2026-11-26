<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

interface ShippingTransport
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function request(string $operation, array $payload): array;
}
