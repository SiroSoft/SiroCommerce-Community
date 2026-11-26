<?php

declare(strict_types=1);

namespace App\Modules\Smart\Application;

use Siro\Core\Database;

/**
 * Ops anomaly alert reads + acks (P4 ops intelligence). Detection lives in
 * SmartAggregator::aggregateAnomalies; this service never creates rows.
 */
final class OpsAlertService
{
    /** @return list<array<string, mixed>> */
    public function list(?string $state = null): array
    {
        try {
            $pdo = Database::connection();
            if ($state !== null && $state !== '') {
                $stmt = $pdo->prepare('SELECT kind, state, window_hours, observed, baseline_x100, ratio_x100, first_seen_at, last_seen_at, acked_at, computed_at FROM ops_anomaly_alerts WHERE state = ? ORDER BY last_seen_at DESC LIMIT 200');
                $stmt->execute([$state]);
            } else {
                $stmt = $pdo->query('SELECT kind, state, window_hours, observed, baseline_x100, ratio_x100, first_seen_at, last_seen_at, acked_at, computed_at FROM ops_anomaly_alerts ORDER BY last_seen_at DESC LIMIT 200');
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function ack(string $kind): bool
    {
        if ($kind === '' || strlen($kind) > 32) return false;
        try {
            $stmt = Database::connection()->prepare("UPDATE ops_anomaly_alerts SET state = 'acked', acked_at = ? WHERE kind = ? AND state = 'open'");
            $stmt->execute([date('Y-m-d H:i:s'), $kind]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
