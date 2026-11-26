<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Modules\Integration\Domain\DeliveryPolicy;

final class DeliveryAttempt
{
    /** @return array{state:string,attempts:int,next_attempt_at:int,error:?string} */
    public static function result(int $attempt, int $maxAttempts, ?int $responseStatus, ?string $error, int $now): array
    {
        $failed = $error !== null || $responseStatus === null || $responseStatus < 200 || $responseStatus >= 300;
        if (!$failed) return ['state' => 'delivered', 'attempts' => $attempt, 'next_attempt_at' => 0, 'error' => null];
        $dead = DeliveryPolicy::dead($attempt, $maxAttempts);
        return ['state' => $dead ? 'dead' : 'pending', 'attempts' => $attempt, 'next_attempt_at' => $dead ? 0 : DeliveryPolicy::retryAt($attempt, $now), 'error' => $error ?? ('HTTP ' . (string) $responseStatus)];
    }
}
