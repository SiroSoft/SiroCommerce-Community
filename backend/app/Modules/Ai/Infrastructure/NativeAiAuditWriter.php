<?php

declare(strict_types=1);

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Contracts\AiAuditWriter;

final class NativeAiAuditWriter implements AiAuditWriter
{
    public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void
    {
        // The native seam records the safe audit shape; persistence is supplied by a host extension.
    }
}
