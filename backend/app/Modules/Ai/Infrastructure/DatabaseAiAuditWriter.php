<?php

declare(strict_types=1);

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Contracts\AiAuditWriter;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Best-effort AI audit rows (spec LLM_GATEWAY_SPEC). Never throws: audit
 * must not break the request it observes.
 */
final class DatabaseAiAuditWriter implements AiAuditWriter
{
    /** @param array<string, mixed> $metadata */
    public function record(string $tenantId, string $actorId, string $task, string $status, array $metadata = []): void
    {
        try {
            $usage = $metadata['usage'] ?? null;
            Database::connection()->prepare('INSERT INTO ai_audits (id, tenant_id, actor_id, task, status, provider, model, usage_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                Ids::new(),
                substr($tenantId, 0, 64),
                substr($actorId, 0, 64),
                substr($task, 0, 64),
                substr($status, 0, 32),
                isset($metadata['provider']) && is_string($metadata['provider']) ? substr($metadata['provider'], 0, 32) : null,
                isset($metadata['model']) && is_string($metadata['model']) ? substr($metadata['model'], 0, 128) : null,
                is_array($usage) ? substr((string) json_encode($usage), 0, 2048) : null,
                date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }
}
