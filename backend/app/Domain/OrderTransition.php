<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Named order transitions only (spec 02§2, 21§2). No arbitrary status writes.
 */
final class OrderTransition
{
    /** @var array<string, string[]> */
    private const ALLOWED = [
        'pending' => ['awaiting_payment', 'cancelled', 'failed'],
        'awaiting_payment' => ['paid', 'cancelled', 'failed'],
        'paid' => ['processing', 'refunded', 'partially_refunded', 'failed'],
        'processing' => ['ready_to_ship', 'refunded', 'partially_refunded', 'failed'],
        'partially_refunded' => ['refunded', 'failed'],
        'ready_to_ship' => ['shipped', 'failed'],
        'shipped' => ['delivered', 'failed'],
        'delivered' => [],
        'failed' => [],
        'cancelled' => [],
        'refunded' => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (!self::can($from, $to)) {
            throw new \InvalidArgumentException("Illegal transition {$from} -> {$to}");
        }
    }
}
