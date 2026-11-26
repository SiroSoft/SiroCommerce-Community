<?php
declare(strict_types=1);
namespace App\Modules\Payment\Domain;

final class PaymentStatusBoundary
{
    /** @var array<string, string[]> */
    private const TRANSITIONS = [
        'pending' => ['authorized', 'failed', 'unknown'],
        'authorized' => ['captured', 'voided', 'unknown'],
        'captured' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['refunded'],
        'unknown' => ['pending', 'authorized', 'captured', 'failed'],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) throw new \DomainException('Invalid payment status transition.');
    }
}
