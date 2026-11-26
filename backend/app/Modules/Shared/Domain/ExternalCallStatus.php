<?php
declare(strict_types=1);
namespace App\Modules\Shared\Domain;

/** External effects are evidence-gated. Missing live evidence is never PASS. */
final class ExternalCallStatus
{
    public const BLOCKED_EXTERNAL = 'BLOCKED_EXTERNAL';
    public const PASS = 'PASS';

    /** @return array<string, string> */
    public static function blocked(string $provider, string $reason): array
    {
        return ['status' => self::BLOCKED_EXTERNAL, 'provider' => $provider, 'reason' => $reason];
    }
}
