<?php
declare(strict_types=1);
namespace App\Modules\Checkout\Contracts;
interface OrderStatePort
{
    /** @return array<string, mixed> */
    public function transition(string $orderId, string $to, string $actor, string $reason = ''): array;
}
