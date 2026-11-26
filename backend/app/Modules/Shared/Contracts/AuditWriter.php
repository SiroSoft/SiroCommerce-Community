<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\Application\CommandContext;

interface AuditWriter
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function record(
        CommandContext $context,
        string $action,
        string $targetType,
        string $targetId,
        array $before,
        array $after,
        string $reason,
    ): void;
}
