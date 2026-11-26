<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

final class DeliveryPolicy
{
    public static function retryAt(int $attempt, int $now, int $maxDelay = 3600): int
    {
        if ($attempt < 1) throw new \InvalidArgumentException('Attempt must be positive.');
        return $now + min($maxDelay, 2 ** min($attempt - 1, 10));
    }

    public static function dead(int $attempt, int $maxAttempts): bool
    {
        return $maxAttempts < 1 || $attempt >= $maxAttempts;
    }
}
