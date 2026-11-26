<?php

declare(strict_types=1);

namespace App\Support;

/** Shared evidence classifications; external blockers are never successful evidence. */
final class EvidenceStatus
{
    /** @return array<string, string> */
    public static function blockedExternal(string $reason, string $owner, string $prerequisite, string $command): array
    {
        return [
            'status' => 'BLOCKED_EXTERNAL',
            'reason' => $reason,
            'owner' => $owner,
            'prerequisite' => $prerequisite,
            'command' => $command,
        ];
    }
}
