<?php
declare(strict_types=1);
namespace App\Modules\Shipping\Contracts;

interface DeliveryMethodContract
{
    /** @param array<string, mixed> $context */
    public function available(array $context): bool;
    /** @param array<string, mixed> $context */
    public function costMinor(array $context): int;
}
