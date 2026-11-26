<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Support\CommerceScope;
use App\Support\Ids;
use App\Jobs\OrderNotificationJob;
use Siro\Core\Database;
use Siro\Core\Queue;

/** Read-only operational evidence plus safe retries for failed commerce jobs. */
final class AdminOperationsService
{
    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function list(CommerceScope $scope, int $page, int $perPage, string $kind = '', string $state = '', string $query = ''): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $sources = [
            ['job', "SELECT id, 'job' AS kind, name AS label, state, attempts, last_error, available_at, NULL AS order_id, available_at AS created_at FROM commerce_jobs WHERE (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) AND (seller_id = ? OR (seller_id IS NULL AND ? IS NULL)) AND (site_id = ? OR (site_id IS NULL AND ? IS NULL)) AND store_id = ? AND channel_id = ?"],
            ['payment', "SELECT po.id, 'payment' AS kind, po.type AS label, po.state, NULL AS attempts, po.last_error, NULL AS available_at, po.payment_id AS order_id, po.created_at FROM payment_operations po INNER JOIN payments p ON p.id = po.payment_id INNER JOIN orders o ON o.id = p.order_id WHERE o.store_id = ? AND o.channel_id = ? AND po.state IN ('failed', 'unknown')"],
            ['fulfillment', "SELECT f.id, 'fulfillment' AS kind, 'fulfillment' AS label, f.state, NULL AS attempts, NULL AS last_error, NULL AS available_at, f.order_id, f.created_at FROM fulfillment_orders f INNER JOIN orders o ON o.id = f.order_id WHERE o.store_id = ? AND o.channel_id = ?"],
            ['notification', "SELECT d.id, 'notification' AS kind, d.event_name AS label, d.state, d.attempts, d.last_error, NULL AS available_at, o.id AS order_id, d.created_at FROM order_notification_deliveries d INNER JOIN orders o ON (o.id = d.aggregate_id OR EXISTS (SELECT 1 FROM fulfillment_orders f WHERE f.id = d.aggregate_id AND f.order_id = o.id) OR EXISTS (SELECT 1 FROM return_requests r WHERE r.id = d.aggregate_id AND r.order_id = o.id)) WHERE o.store_id = ? AND o.channel_id = ? AND d.state IN ('failed', 'sending')"],
            ['return', "SELECT r.id, 'return' AS kind, 'return_refund' AS label, COALESCE(r.refund_state, r.state) AS state, NULL AS attempts, r.refund_error AS last_error, NULL AS available_at, r.order_id, r.created_at FROM return_requests r INNER JOIN orders o ON o.id = r.order_id WHERE o.store_id = ? AND o.channel_id = ? AND (r.state IN ('inspected', 'approved_refund') OR r.refund_state = 'failed')"],
        ];
        $parts = [];
        $params = [];
        foreach ($sources as [$sourceKind, $sql]) {
            if ($kind !== '' && $kind !== $sourceKind) continue;
            if ($sourceKind === 'job') {
                array_push($params, $scope->tenantId, $scope->tenantId, $scope->sellerId, $scope->sellerId, $scope->siteId, $scope->siteId);
            }
            $params[] = $scope->storeId;
            $params[] = $scope->channelId;
            $parts[] = $sql;
        }
        if ($parts === []) return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
        $db = Database::connection();
        $union = implode(' UNION ALL ', $parts);
        $filter = ' WHERE (? = \'\' OR state = ?) AND (? = \'\' OR id LIKE ? OR label LIKE ? OR last_error LIKE ?)';
        $needle = '%' . $query . '%';
        $filterParams = [$state, $state, $query, $needle, $needle, $needle];
        $count = $db->prepare('SELECT COUNT(*) FROM (' . $union . ') operational_rows' . $filter);
        $count->execute(array_merge($params, $filterParams));
        $total = (int) $count->fetchColumn();
        $statement = $db->prepare('SELECT * FROM (' . $union . ') operational_rows' . $filter . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        $statement->execute(array_merge($params, $filterParams, [$perPage, ($page - 1) * $perPage]));
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (string) ($row['id'] ?? ''), 'kind' => (string) ($row['kind'] ?? ''),
                'label' => (string) ($row['label'] ?? ''), 'state' => (string) ($row['state'] ?? ''),
                'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : null,
                'last_error' => is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
                'available_at' => $row['available_at'] ?? null, 'order_id' => $row['order_id'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }

    /** @return array<string, mixed> */
    public function retryJob(string $id, CommerceScope $scope, string $key, string $actor, string $correlationId): array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $key) !== 1) throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        $db = Database::connection();
        $existing = $db->prepare("SELECT payload_json FROM commerce_audit_events WHERE action = 'admin.operation.retry' AND store_id = ? AND channel_id = ? AND payload_json LIKE ? ORDER BY created_at DESC LIMIT 1");
        $existing->execute([$scope->storeId, $scope->channelId, '%"idempotency_key":"' . addcslashes($key, '\\"') . '"%']);
        $replay = $existing->fetchColumn();
        if (is_string($replay)) { $decoded = json_decode($replay, true); if (is_array($decoded) && is_array($decoded['result'] ?? null)) return $decoded['result']; }
        $find = $db->prepare('SELECT * FROM commerce_jobs WHERE id = ? AND store_id = ? AND channel_id = ?');
        $find->execute([$id, $scope->storeId, $scope->channelId]);
        $job = $find->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($job)) throw new \RuntimeException('Job not found');
        $state = is_string($job['state'] ?? null) ? $job['state'] : '';
        if (!in_array($state, ['failed', 'dead', 'cancelled'], true)) throw new \RuntimeException('Only failed jobs can be retried');
        $update = $db->prepare('UPDATE commerce_jobs SET state = ?, available_at = ?, locked_at = NULL, worker_id = NULL, last_error = NULL WHERE id = ? AND store_id = ? AND channel_id = ? AND state = ?');
        if (!$update->execute(['queued', date('Y-m-d H:i:s'), $id, $scope->storeId, $scope->channelId, $state]) || $update->rowCount() !== 1) throw new \RuntimeException('Job changed concurrently');
        $result = ['id' => $id, 'kind' => 'job', 'state' => 'queued', 'previous_state' => $state, 'idempotency_key' => $key];
        $db->prepare('INSERT INTO commerce_audit_events (id, action, actor, payload_json, tenant_id, seller_id, site_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Ids::new(), 'admin.operation.retry', $actor, json_encode(['id' => $id, 'idempotency_key' => $key, 'correlation_id' => $correlationId, 'result' => $result], JSON_THROW_ON_ERROR), $scope->tenantId, $scope->sellerId, $scope->siteId, $scope->storeId, $scope->channelId, date('Y-m-d H:i:s')]);
        return $result;
    }

    /** Replay a failed delivery without changing its delivery key or customer data. */
    public function replayNotification(string $id, CommerceScope $scope, string $key, string $actor, string $correlationId): array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $key) !== 1) throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        $db = Database::connection();
        $audit = $db->prepare("SELECT payload_json FROM commerce_audit_events WHERE action = 'admin.operation.notification_replay' AND store_id = ? AND channel_id = ? AND payload_json LIKE ? ORDER BY created_at DESC LIMIT 1");
        $audit->execute([$scope->storeId, $scope->channelId, '%"idempotency_key":"' . addcslashes($key, '\\"') . '"%']);
        $previous = $audit->fetchColumn();
        if (is_string($previous)) { $decoded = json_decode($previous, true); if (is_array($decoded) && is_array($decoded['result'] ?? null)) return $decoded['result']; }
        $find = $db->prepare("SELECT d.*, o.store_id, o.channel_id FROM order_notification_deliveries d INNER JOIN orders o ON (o.id = d.aggregate_id OR EXISTS (SELECT 1 FROM fulfillment_orders f WHERE f.id = d.aggregate_id AND f.order_id = o.id) OR EXISTS (SELECT 1 FROM return_requests r WHERE r.id = d.aggregate_id AND r.order_id = o.id)) WHERE d.id = ? AND o.store_id = ? AND o.channel_id = ?");
        $find->execute([$id, $scope->storeId, $scope->channelId]);
        $delivery = $find->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($delivery)) throw new \RuntimeException('Notification not found');
        if (!in_array((string) ($delivery['state'] ?? ''), ['failed', 'sending', 'dead'], true)) throw new \RuntimeException('Only failed notifications can be replayed');
        $update = $db->prepare("UPDATE order_notification_deliveries SET state = 'queued', locked_at = NULL, last_error = NULL, next_attempt_at = NULL, dead_lettered_at = NULL, replay_count = replay_count + 1 WHERE id = ? AND state IN ('failed', 'sending', 'dead')");
        if (!$update->execute([$id]) || $update->rowCount() !== 1) throw new \RuntimeException('Notification changed concurrently');
        Queue::push(OrderNotificationJob::class, ['event_name' => (string) $delivery['event_name'], 'aggregate_id' => (string) $delivery['aggregate_id'], 'template' => (string) $delivery['template'], 'delivery_key' => (string) $delivery['delivery_key']], 0, 0, 5);
        $result = ['id' => $id, 'kind' => 'notification', 'state' => 'queued', 'idempotency_key' => $key];
        $db->prepare('INSERT INTO commerce_audit_events (id, action, actor, payload_json, tenant_id, seller_id, site_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Ids::new(), 'admin.operation.notification_replay', $actor, json_encode(['id' => $id, 'idempotency_key' => $key, 'correlation_id' => $correlationId, 'result' => $result], JSON_THROW_ON_ERROR), $scope->tenantId, $scope->sellerId, $scope->siteId, $scope->storeId, $scope->channelId, date('Y-m-d H:i:s')]);
        return $result;
    }
}
