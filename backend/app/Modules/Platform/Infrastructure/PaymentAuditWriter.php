<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Shared\Application\CommandContext;
use App\Modules\Shared\Contracts\AuditWriter;
use App\Support\Ids;
use Siro\Core\Database;

final class PaymentAuditWriter implements AuditWriter
{
    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function record(CommandContext $context, string $action, string $targetType, string $targetId, array $before, array $after, string $reason): void
    {
        $payload = [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before' => $this->snapshot($before),
            'after' => $this->snapshot($after),
            'reason' => $reason,
            'correlation_id' => $context->correlationId,
            'causation_id' => $context->causationId,
            'idempotency_key' => $context->idempotencyKey,
            'source' => $context->source,
        ];
        Database::connection()->prepare('INSERT INTO commerce_audit_events (id, action, actor, payload_json, tenant_id, seller_id, site_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            Ids::new(),
            $action,
            $context->actor,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            null,
            null,
            null,
            'default',
            'default',
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function snapshot(array $snapshot): array
    {
        $allowed = ['id', 'state', 'amount_minor', 'captured_amount_minor', 'currency', 'provider', 'provider_ref'];
        /** @var array<string, mixed> $safe */
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $snapshot) && (is_string($snapshot[$key]) || is_int($snapshot[$key]) || $snapshot[$key] === null)) {
                $safe[$key] = $snapshot[$key];
            }
        }
        return $safe;
    }
}
