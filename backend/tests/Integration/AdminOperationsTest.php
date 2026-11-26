<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Platform\Application\AdminOperationsService;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

final class AdminOperationsTest extends TestCase
{
    private function scope(string $store = 'ops-store'): CommerceScope
    {
        return new CommerceScope($store, 'web', 'en', 'VND');
    }

    public function testOperationsAreScopedFilteredAndPaginated(): void
    {
        $db = Database::connection();
        $insert = $db->prepare('INSERT INTO commerce_jobs (id, queue, name, idempotency_key, payload_json, state, attempts, max_attempts, available_at, store_id, channel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['job-a', 'commerce', 'shipment-a', 'key-a', '{}', 'failed', 2, 5, '2026-09-29 10:00:00', 'ops-store', 'web']);
        $insert->execute(['job-b', 'commerce', 'shipment-b', 'key-b', '{}', 'dead', 5, 5, '2026-09-29 09:00:00', 'other-store', 'web']);
        $insert->execute(['job-c', 'commerce', 'payment-c', 'key-c', '{}', 'failed', 1, 5, '2026-09-29 08:00:00', 'ops-store', 'web']);

        $result = (new AdminOperationsService())->list($this->scope(), 1, 1, 'job', 'failed', 'shipment');
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('job-a', $result['data'][0]['id']);
    }

    public function testRetryIsIdempotentAndLeavesAuditEvidence(): void
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO commerce_jobs (id, queue, name, idempotency_key, payload_json, state, attempts, max_attempts, available_at, store_id, channel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['job-retry', 'commerce', 'shipment', 'key-retry', '{}', 'dead', 5, 5, '2026-09-29 10:00:00', 'ops-store', 'web']);
        $service = new AdminOperationsService();
        $first = $service->retryJob('job-retry', $this->scope(), 'retry-key', 'operator@example.test', 'corr-1');
        $second = $service->retryJob('job-retry', $this->scope(), 'retry-key', 'operator@example.test', 'corr-2');
        self::assertSame($first, $second);
        self::assertSame('queued', $db->query("SELECT state FROM commerce_jobs WHERE id = 'job-retry'")->fetchColumn());
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM commerce_audit_events WHERE action = 'admin.operation.retry'")->fetchColumn());
    }

    public function testJobEvidenceCannotCrossTenantScope(): void
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO commerce_jobs (id, queue, name, idempotency_key, payload_json, state, attempts, max_attempts, available_at, tenant_id, store_id, channel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['job-tenant-a', 'commerce', 'private-a', 'key-tenant-a', '{}', 'failed', 1, 5, '2026-09-29 10:00:00', 'tenant-a', 'ops-store', 'web']);
        $db->prepare('INSERT INTO commerce_jobs (id, queue, name, idempotency_key, payload_json, state, attempts, max_attempts, available_at, tenant_id, store_id, channel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['job-tenant-b', 'commerce', 'private-b', 'key-tenant-b', '{}', 'failed', 1, 5, '2026-09-29 10:00:00', 'tenant-b', 'ops-store', 'web']);

        $result = (new AdminOperationsService())->list(new CommerceScope('ops-store', 'web', 'en', 'VND', 'tenant-a'), 1, 25, 'job');
        self::assertSame(['job-tenant-a'], array_column($result['data'], 'id'));
    }
}
