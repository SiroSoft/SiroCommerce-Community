<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Queue;
use App\Jobs\CommerceQueueJob;

/** Contract-safe admin parity operations. External provider execution is intentionally absent. */
final class ParityService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function enqueue(array $payload, CommerceScope $scope, string $key, string $actor): array
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $key)) throw new \InvalidArgumentException('Valid Idempotency-Key is required');
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ($name === '') throw new \InvalidArgumentException('Job name is required');
        $fingerprint = hash('sha256', json_encode([$name, $payload['payload'] ?? [], $scope->key()], JSON_THROW_ON_ERROR));
        $db = Database::connection();
        $existing = $db->prepare('SELECT * FROM commerce_jobs WHERE idempotency_key = ?');
        $existing->execute([$scope->key() . ':' . $key]);
        $row = $existing->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $payloadJson = is_string($row['payload_json'] ?? null) ? $row['payload_json'] : '{}';
            $old = json_decode($payloadJson, true);
            if (!is_array($old) || ($old['_fingerprint'] ?? '') !== $fingerprint) throw new \RuntimeException('Idempotency key conflicts with the job request');
            return self::row($row);
        }
        $id = Ids::new();
        $data = ['data' => is_array($payload['payload'] ?? null) ? $payload['payload'] : [], '_fingerprint' => $fingerprint, 'actor' => $actor, 'scope' => $scope->metadata()];
        $statement = $db->prepare('INSERT INTO commerce_jobs (id, queue, name, idempotency_key, payload_json, state, attempts, max_attempts, available_at, tenant_id, seller_id, site_id, store_id, channel_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)');
        $maxAttempts = is_int($payload['max_attempts'] ?? null) ? $payload['max_attempts'] : 5;
        $statement->execute([$id, 'commerce', $name, $scope->key() . ':' . $key, json_encode($data, JSON_THROW_ON_ERROR), 'queued', max(1, $maxAttempts), date('Y-m-d H:i:s'), $scope->tenantId, $scope->sellerId, $scope->siteId, $scope->storeId, $scope->channelId]);
        Queue::push(CommerceQueueJob::class, ['job_id' => $id, 'name' => $name, 'payload' => is_array($payload['payload'] ?? null) ? $payload['payload'] : []], 0, 0, $maxAttempts);
        $this->recordAudit('job.enqueued', $actor, $scope, ['job_id' => $id, 'name' => $name]);
        return ['id' => $id, 'name' => $name, 'state' => 'queued', 'idempotency_key' => $key, 'scope' => $scope->metadata()];
    }

    /** @return array<string, mixed>|null */
    public function job(string $id, CommerceScope $scope): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM commerce_jobs WHERE id = ? AND (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) AND (seller_id = ? OR (seller_id IS NULL AND ? IS NULL)) AND (site_id = ? OR (site_id IS NULL AND ? IS NULL)) AND store_id = ? AND channel_id = ?');
        $statement->execute([$id, $scope->tenantId, $scope->tenantId, $scope->sellerId, $scope->sellerId, $scope->siteId, $scope->siteId, $scope->storeId, $scope->channelId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @return array<string, mixed> */
    public function changeJob(string $id, string $action, CommerceScope $scope): array
    {
        $job = $this->job($id, $scope);
        if ($job === null) throw new \RuntimeException('Job not found');
        $state = self::stringValue($job['state'] ?? null);
        if ($action === 'cancel') {
            if (in_array($state, ['completed', 'dead', 'cancelled'], true)) throw new \RuntimeException('Job cannot be cancelled in its current state');
            $next = 'cancelled';
        } elseif ($action === 'retry') {
            if (!in_array($state, ['dead', 'failed', 'cancelled'], true)) throw new \RuntimeException('Only failed jobs can be retried');
            $next = 'queued';
        } else throw new \InvalidArgumentException('Unsupported job action');
        $statement = Database::connection()->prepare('UPDATE commerce_jobs SET state = ?, available_at = ?, locked_at = NULL, worker_id = NULL, last_error = NULL WHERE id = ? AND state = ?');
        if ($statement->execute([$next, date('Y-m-d H:i:s'), $id, $state]) !== true || $statement->rowCount() !== 1) throw new \RuntimeException('Job changed concurrently');
        $changed = $this->job($id, $scope);
        return $changed ?? ['id' => $id, 'state' => $next];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function transfer(array $data, CommerceScope $scope, string $key, string $actor): array
    {
        $sourceCheck = Database::connection()->prepare('SELECT source_id FROM inventory_sources WHERE source_id IN (?, ?) AND (store_id IS NULL OR store_id = ?) AND (site_id IS NULL OR site_id = ?)');
        $sourceCheck->execute([self::stringValue($data['from_source_id'] ?? null), self::stringValue($data['to_source_id'] ?? null), $scope->storeId, $scope->siteId]);
        if (count($sourceCheck->fetchAll(\PDO::FETCH_ASSOC)) !== 2) throw new \RuntimeException('Warehouse source is outside the commerce scope');
        $data['idempotency_key'] = $scope->key() . ':' . $key;
        $data['actor'] = $actor;
        $data['store_id'] = $scope->storeId;
        $data['site_id'] = $scope->siteId;
        $result = (new \App\Repositories\InventoryRepository())->transfer($data);
        Database::connection()->prepare('UPDATE inventory_transfers SET tenant_id = ?, seller_id = ?, site_id = ?, store_id = ? WHERE id = ?')->execute([$scope->tenantId, $scope->sellerId, $scope->siteId, $scope->storeId, self::stringValue($result['id'] ?? null)]);
        $this->recordAudit('inventory.transfer', $actor, $scope, ['transfer_id' => self::stringValue($result['id'] ?? null)]);
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public function reserve(array $data, CommerceScope $scope, string $key): array
    {
        $manager = new \App\Modules\Inventory\Application\InventoryManager(new \App\Modules\Platform\Infrastructure\InventoryStoreAdapter(new \App\Repositories\InventoryRepository()));
        return $manager->reserveAcrossSources(self::stringValue($data['variant_id'] ?? null), self::intValue($data['qty'] ?? null), self::stringValue($data['cart_id'] ?? null), self::intValue($data['ttl_seconds'] ?? 900), ['allow_split' => true], $scope->key() . ':' . $key, $scope->storeId, null);
    }

    public function release(string $id, CommerceScope $scope): void
    {
        $row = Database::connection()->prepare('SELECT id FROM reservations WHERE id = ? AND store_id = ?');
        $row->execute([$id, $scope->storeId]);
        if (!is_array($row->fetch(\PDO::FETCH_ASSOC))) throw new \RuntimeException('Reservation not found');
        (new \App\Modules\Inventory\Application\InventoryManager(new \App\Modules\Platform\Infrastructure\InventoryStoreAdapter(new \App\Repositories\InventoryRepository())))->release($id, 'admin-release');
        $this->recordAudit('inventory.reservation.released', 'system', $scope, ['reservation_id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function audit(CommerceScope $scope, int $limit, int $offset): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM commerce_audit_events WHERE (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) AND (seller_id = ? OR (seller_id IS NULL AND ? IS NULL)) AND (site_id = ? OR (site_id IS NULL AND ? IS NULL)) AND store_id = ? AND channel_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $statement->execute([$scope->tenantId, $scope->tenantId, $scope->sellerId, $scope->sellerId, $scope->siteId, $scope->siteId, $scope->storeId, $scope->channelId, $limit, $offset]);
        return self::auditRows($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    public function notifications(CommerceScope $scope, int $limit, int $offset): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM commerce_notification_events WHERE (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) AND (seller_id = ? OR (seller_id IS NULL AND ? IS NULL)) AND (site_id = ? OR (site_id IS NULL AND ? IS NULL)) AND store_id = ? AND channel_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $statement->execute([$scope->tenantId, $scope->tenantId, $scope->sellerId, $scope->sellerId, $scope->siteId, $scope->siteId, $scope->storeId, $scope->channelId, $limit, $offset]);
        return self::rows($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    public function vendors(CommerceScope $scope, int $limit, int $offset): array
    {
        $statement = Database::connection()->prepare('SELECT id, code, name, seller_id, state, created_at FROM commerce_vendors WHERE (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) AND (seller_id = ? OR (seller_id IS NULL AND ? IS NULL)) AND store_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $statement->execute([$scope->tenantId, $scope->tenantId, $scope->sellerId, $scope->sellerId, $scope->storeId, $limit, $offset]);
        return self::rows($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function vendor(string $id, CommerceScope $scope): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, code, name, seller_id, state, created_at FROM commerce_vendors WHERE id = ? AND (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) AND (seller_id = ? OR (seller_id IS NULL AND ? IS NULL)) AND store_id = ?');
        $statement->execute([$id, $scope->tenantId, $scope->tenantId, $scope->sellerId, $scope->sellerId, $scope->storeId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::row($row) : null;
    }

    /** @param array<string, mixed> $payload */
    private function recordAudit(string $action, string $actor, CommerceScope $scope, array $payload): void
    {
        Database::connection()->prepare('INSERT INTO commerce_audit_events (id, action, actor, payload_json, tenant_id, seller_id, site_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Ids::new(), $action, $actor, json_encode($payload, JSON_THROW_ON_ERROR), $scope->tenantId, $scope->sellerId, $scope->siteId, $scope->storeId, $scope->channelId, date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> */
    private static function row(mixed $row): array { $result = []; if (is_array($row)) foreach ($row as $key => $value) if (is_string($key)) $result[$key] = $value; return $result; }
    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private static function rows(mixed $rows): array { $result = []; if (is_array($rows)) foreach ($rows as $row) $result[] = self::row($row); return $result; }
    /** Audit is an operational timeline, not a PII export. */
    private static function auditRows(mixed $rows): array
    {
        $result = [];
        if (!is_array($rows)) return $result;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $result[] = [
                'id' => (string) ($row['id'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'actor' => self::maskActor((string) ($row['actor'] ?? '')),
                'payload' => self::redact(is_array($payload) ? $payload : []),
                'store_id' => (string) ($row['store_id'] ?? ''),
                'channel_id' => (string) ($row['channel_id'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $result;
    }
    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function redact(array $value): array
    {
        $blocked = ['email', 'phone', 'address', 'password', 'token', 'secret', 'authorization'];
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, $blocked, true) || str_contains($normalized, 'email') || str_contains($normalized, 'phone')) {
                $result[(string) $key] = '[redacted]';
            } elseif (is_array($item)) {
                $result[(string) $key] = self::redact($item);
            } elseif (is_scalar($item) || $item === null) {
                $result[(string) $key] = $item;
            }
        }
        return $result;
    }
    private static function maskActor(string $actor): string
    {
        if (!str_contains($actor, '@')) return $actor === '' ? 'system' : $actor;
        [$name, $domain] = explode('@', $actor, 2);
        return substr($name, 0, 1) . '***@' . $domain;
    }
    private static function stringValue(mixed $value): string { return is_string($value) ? $value : ''; }
    private static function intValue(mixed $value): int { return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0); }
}
