<?php
declare(strict_types=1);
namespace App\Modules\Queue\Domain;

final class JobStatus
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const BLOCKED_EXTERNAL = 'blocked_external';
    public const DEAD_LETTER = 'dead_letter';

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::SUCCEEDED, self::FAILED, self::BLOCKED_EXTERNAL, self::DEAD_LETTER], true);
    }
}
