<?php
declare(strict_types=1);
namespace App\Modules\Order\Domain;
final class OrderStateMachine
{
    /** @var array<string, string[]> */
    private const ALLOWED = [
        'pending' => ['awaiting_payment', 'cancelled', 'failed'], 'awaiting_payment' => ['paid', 'cancelled', 'failed'],
        'paid' => ['processing', 'refunded', 'partially_refunded', 'failed'], 'processing' => ['ready_to_ship', 'refunded', 'partially_refunded', 'failed'], 'partially_refunded' => ['refunded', 'failed'],
        'ready_to_ship' => ['shipped', 'ready_for_pickup', 'failed'], 'shipped' => ['delivered', 'failed'], 'ready_for_pickup' => ['picked_up', 'failed'], 'picked_up' => [], 'delivered' => [], 'failed' => [], 'cancelled' => [], 'refunded' => [],
    ];
    public static function assert(string $from, string $to): void
    {
        if (!in_array($to, self::ALLOWED[$from] ?? [], true)) throw new \InvalidArgumentException("Illegal transition {$from} -> {$to}");
    }
}
