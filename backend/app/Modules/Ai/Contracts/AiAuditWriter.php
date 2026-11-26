<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

interface AiAuditWriter
{
    /** @param array<string, mixed> $metadata */
    public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void;
}
