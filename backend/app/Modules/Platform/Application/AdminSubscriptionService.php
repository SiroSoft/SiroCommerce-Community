<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Queue;
use App\Jobs\SubscriptionRenewalJob;

/** Scoped subscription read model. Customer identity and provider evidence never leave this boundary. */
final class AdminSubscriptionService
{
    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function list(CommerceScope $scope, int $page, int $perPage, string $state = '', string $query = ''): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $where = 's.store_id = ? AND s.channel_id = ?';
        $params = [$scope->storeId, $scope->channelId];
        if ($state !== '') { $where .= ' AND s.state = ?'; $params[] = $state; }
        if ($query !== '') {
            $like = '%' . substr($query, 0, 80) . '%';
            $where .= ' AND (s.id LIKE ? OR s.plan_id LIKE ? OR s.state LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $db = Database::connection();
        $count = $db->prepare('SELECT COUNT(*) FROM subscriptions s WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $db->prepare('SELECT s.id, s.customer_id, s.plan_id, s.provider, s.currency, s.amount_minor, s.interval, s.interval_count, s.state, s.started_at, s.paused_at, s.cancelled_at, s.next_billing_at, s.created_at, (SELECT a.state FROM subscription_renewal_attempts a WHERE a.subscription_id = s.id ORDER BY a.created_at DESC, a.id DESC LIMIT 1) AS latest_attempt_state FROM subscriptions s WHERE ' . $where . ' ORDER BY s.created_at DESC, s.id DESC LIMIT ? OFFSET ?');
        $statement->execute(array_merge($params, [$perPage, ($page - 1) * $perPage]));
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) $rows[] = $this->subscriptionRow($row);
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }

    /** @return array<string, mixed>|null */
    public function detail(string $id, CommerceScope $scope): ?array
    {
        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM subscriptions WHERE id = ? AND store_id = ? AND channel_id = ?');
        $statement->execute([$id, $scope->storeId, $scope->channelId]);
        $subscription = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($subscription)) return null;
        $attempts = $db->prepare('SELECT id, state, amount_minor, currency, provider, attempts, next_attempt_at, created_at, completed_at, last_error FROM subscription_renewal_attempts WHERE subscription_id = ? ORDER BY created_at DESC, id DESC LIMIT 20');
        $attempts->execute([$id]);
        $result = $this->subscriptionRow($subscription);
        $result['renewals'] = array_map(fn (array $row): array => $this->attemptRow($row), $attempts->fetchAll(\PDO::FETCH_ASSOC));
        return $result;
    }

    /** @return array<string, mixed> */
    public function summary(CommerceScope $scope, ?string $from, ?string $to): array
    {
        [$start, $end] = $this->window($from, $to);
        $db = Database::connection();
        $scopeParams = [$scope->storeId, $scope->channelId];
        $states = $db->prepare('SELECT state, COUNT(*) AS count FROM subscriptions WHERE store_id = ? AND channel_id = ? GROUP BY state');
        $states->execute($scopeParams);
        $counts = ['active' => 0, 'paused' => 0, 'cancelled' => 0];
        foreach ($states->fetchAll(\PDO::FETCH_ASSOC) as $row) $counts[(string) $row['state']] = (int) $row['count'];
        $revenue = $db->prepare("SELECT a.currency, SUM(a.amount_minor) AS amount_minor, COUNT(*) AS payments FROM subscription_renewal_attempts a INNER JOIN subscriptions s ON s.id = a.subscription_id WHERE s.store_id = ? AND s.channel_id = ? AND a.state = 'succeeded' AND a.completed_at >= ? AND a.completed_at <= ? GROUP BY a.currency");
        $revenue->execute([$scope->storeId, $scope->channelId, $start, $end]);
        $recurring = [];
        foreach ($revenue->fetchAll(\PDO::FETCH_ASSOC) as $row) $recurring[(string) $row['currency']] = ['amount_minor' => (int) $row['amount_minor'], 'payments' => (int) $row['payments']];
        $activeRows = $db->prepare("SELECT currency, amount_minor, interval, interval_count FROM subscriptions WHERE store_id = ? AND channel_id = ? AND state = 'active'");
        $activeRows->execute($scopeParams);
        $activeRecurring = [];
        foreach ($activeRows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $months = match ((string) $row['interval']) { 'year', 'years' => 12, 'week', 'weeks' => 12 / 52, 'day', 'days' => 12 / 365, default => 1 };
            $currency = (string) $row['currency'];
            $activeRecurring[$currency] = ($activeRecurring[$currency] ?? 0) + (int) round(((int) $row['amount_minor'] * (int) $row['interval_count']) / $months);
        }
        $failed = $db->prepare("SELECT COUNT(*) FROM subscription_renewal_attempts a INNER JOIN subscriptions s ON s.id = a.subscription_id WHERE s.store_id = ? AND s.channel_id = ? AND a.state IN ('failed', 'retryable', 'unknown')");
        $failed->execute($scopeParams);
        $base = $counts['active'] + $counts['cancelled'];
        return ['window' => ['from' => $start, 'to' => $end], 'active' => $counts['active'], 'paused' => $counts['paused'], 'cancelled' => $counts['cancelled'], 'churn_rate' => $base === 0 ? null : round($counts['cancelled'] / $base, 4), 'active_recurring_revenue' => $activeRecurring, 'renewal_revenue' => $recurring, 'payment_failure_queue' => (int) $failed->fetchColumn(), 'privacy' => ['pii' => false]];
    }

    /** @return array<string, mixed> */
    public function retry(string $attemptId, CommerceScope $scope, string $key, string $actor, string $correlationId): array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $key) !== 1) throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        $db = Database::connection();
        $audit = $db->prepare("SELECT payload_json FROM commerce_audit_events WHERE action = 'admin.subscription.retry' AND store_id = ? AND channel_id = ? AND payload_json LIKE ? ORDER BY created_at DESC LIMIT 1");
        $audit->execute([$scope->storeId, $scope->channelId, '%"idempotency_key":"' . addcslashes($key, '\\"') . '"%']);
        $previous = $audit->fetchColumn();
        if (is_string($previous)) { $decoded = json_decode($previous, true); if (is_array($decoded['result'] ?? null)) return $decoded['result']; }
        $find = $db->prepare("SELECT a.* FROM subscription_renewal_attempts a INNER JOIN subscriptions s ON s.id = a.subscription_id WHERE a.id = ? AND s.store_id = ? AND s.channel_id = ?");
        $find->execute([$attemptId, $scope->storeId, $scope->channelId]);
        $attempt = $find->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($attempt)) throw new \RuntimeException('Renewal attempt not found');
        if (!in_array((string) $attempt['state'], ['failed', 'retryable', 'unknown'], true)) throw new \RuntimeException('Only failed renewals can be retried');
        $renewalKey = 'admin-renewal:' . $attemptId . ':' . $key;
        $existing = $db->prepare('SELECT id FROM subscription_renewal_attempts WHERE idempotency_key = ?');
        $existing->execute([$renewalKey]);
        if ($existing->fetchColumn()) throw new \RuntimeException('Idempotency key already used');
        $now = date('Y-m-d H:i:s');
        $db->prepare('INSERT INTO subscription_renewal_attempts (id, subscription_id, idempotency_key, state, amount_minor, currency, provider, evidence_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Ids::new(), $attempt['subscription_id'], $renewalKey, 'pending', $attempt['amount_minor'], $attempt['currency'], $attempt['provider'], json_encode(['admin_retry_of' => $attemptId], JSON_THROW_ON_ERROR), $now]);
        try { Queue::push(SubscriptionRenewalJob::class, ['subscription_id' => (string) $attempt['subscription_id'], 'idempotency_key' => $renewalKey], 0, 0, 5); }
        catch (\Throwable $e) { $db->prepare("UPDATE subscription_renewal_attempts SET state = 'retryable', last_error = ? WHERE idempotency_key = ?")->execute(['Renewal job could not be queued', $renewalKey]); throw new \RuntimeException('Renewal job could not be queued', 0, $e); }
        $result = ['attempt_id' => $attemptId, 'state' => 'pending', 'idempotency_key' => $key];
        $db->prepare('INSERT INTO commerce_audit_events (id, action, actor, payload_json, tenant_id, seller_id, site_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Ids::new(), 'admin.subscription.retry', $actor, json_encode(['attempt_id' => $attemptId, 'idempotency_key' => $key, 'correlation_id' => $correlationId, 'result' => $result], JSON_THROW_ON_ERROR), $scope->tenantId, $scope->sellerId, $scope->siteId, $scope->storeId, $scope->channelId, $now]);
        return $result;
    }

    /** @return array<string, mixed> */
    private function subscriptionRow(array $row): array
    { return ['id' => (string) ($row['id'] ?? ''), 'plan_id' => (string) ($row['plan_id'] ?? ''), 'provider' => (string) ($row['provider'] ?? ''), 'currency' => (string) ($row['currency'] ?? ''), 'amount_minor' => (int) ($row['amount_minor'] ?? 0), 'interval' => (string) ($row['interval'] ?? ''), 'interval_count' => (int) ($row['interval_count'] ?? 1), 'state' => (string) ($row['state'] ?? ''), 'started_at' => $row['started_at'] ?? null, 'paused_at' => $row['paused_at'] ?? null, 'cancelled_at' => $row['cancelled_at'] ?? null, 'next_billing_at' => $row['next_billing_at'] ?? null, 'created_at' => $row['created_at'] ?? null, 'latest_attempt_state' => $row['latest_attempt_state'] ?? null, 'customer_ref' => hash('sha256', (string) ($row['customer_id'] ?? ''))]; }
    /** @return array<string, mixed> */
    private function attemptRow(array $row): array
    { return ['id' => (string) ($row['id'] ?? ''), 'state' => (string) ($row['state'] ?? ''), 'amount_minor' => (int) ($row['amount_minor'] ?? 0), 'currency' => (string) ($row['currency'] ?? ''), 'provider' => (string) ($row['provider'] ?? ''), 'attempts' => (int) ($row['attempts'] ?? 0), 'next_attempt_at' => $row['next_attempt_at'] ?? null, 'created_at' => $row['created_at'] ?? null, 'completed_at' => $row['completed_at'] ?? null, 'error_code' => $row['last_error'] === null ? null : 'payment_failed']; }
    /** @return array{0:string,1:string} */
    private function window(?string $from, ?string $to): array
    { $end = $to === null || strtotime($to) === false ? time() : strtotime($to); $start = $from === null || strtotime($from) === false ? strtotime('-30 days') : strtotime($from); if ($start > $end || ($end - $start) > 366 * 86400) throw new \InvalidArgumentException('Reporting window must be 1-366 days'); return [date('Y-m-d 00:00:00', $start), date('Y-m-d 23:59:59', $end)]; }
}
