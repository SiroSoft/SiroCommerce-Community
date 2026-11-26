<?php

declare(strict_types=1);

namespace App\Controllers;

use App\CommerceVersion;
use App\Support\CommerceScope;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Merchant dashboard aggregates (read-only).
 * Powers the admin overview surface with live MySQL counts.
 */
final class AdminDashboardController extends Controller
{
    public function stats(Request $request): Response
    {
        $started = microtime(true);
        $pdo = Database::connection();
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (\Throwable $e) {
            return $this->error('A valid commerce scope is required', 422, [], 'INVALID_SCOPE');
        }
        // Scope filters are applied at the query boundary. Never derive these
        // values from client-side state or return a cross-store aggregate.
        $scopeWhere = ' AND store_id = ? AND channel_id = ?';
        $scopeParams = [$scope->storeId, $scope->channelId];
        $count = static function (\PDO $pdo, string $sql, array $params = []): int { $query = $pdo->prepare($sql); $query->execute($params); return (int) $query->fetchColumn(); };
        $totalUsers = $count($pdo, 'SELECT COUNT(*) FROM users');
        $activeUsers = $count($pdo, "SELECT COUNT(*) FROM users WHERE status='active'");
        $totalOrders = $count($pdo, 'SELECT COUNT(*) FROM orders WHERE 1=1' . $scopeWhere, $scopeParams);
        $totalProducts = $count($pdo, "SELECT COUNT(*) FROM products WHERE status='active' AND store_id = ? AND channel_id = ?", $scopeParams);
        $revenue = $count($pdo, "SELECT COALESCE(SUM(grand_total_minor),0) FROM orders WHERE state='completed'" . $scopeWhere, $scopeParams);
        $byStatus = [];
        $statusQuery = $pdo->prepare('SELECT state,COUNT(*) AS c FROM orders WHERE 1=1' . $scopeWhere . ' GROUP BY state');
        $statusQuery->execute($scopeParams);
        foreach ($statusQuery->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byStatus[(string) $row['state']] = (int) $row['c'];
        }
        $monthly = [];
        $cutoff = date('Y-m-d H:i:s', strtotime('-6 months'));
        $statement = $pdo->prepare('SELECT created_at,grand_total_minor FROM orders WHERE state=? AND created_at>=?' . $scopeWhere);
        $statement->execute(['completed', $cutoff, ...$scopeParams]);
        $buckets = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $month = substr((string) $row['created_at'], 0, 7);
            $buckets[$month] = ($buckets[$month] ?? 0) + (int) $row['grand_total_minor'];
        }
        ksort($buckets);
        foreach ($buckets as $month => $revenueBucket) $monthly[] = ['month' => $month, 'revenue' => $revenueBucket];
        $activity = [];
        $activityQuery = $pdo->prepare('SELECT id,number,state,created_at FROM orders WHERE 1=1' . $scopeWhere . ' ORDER BY created_at DESC LIMIT 5');
        $activityQuery->execute($scopeParams);
        foreach ($activityQuery->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $activity[] = ['id' => $row['id'], 'action' => 'order.' . $row['state'], 'description' => 'Order ' . $row['number'] . ' is ' . $row['state'], 'user' => 'system', 'created_at' => $row['created_at']];
        }
        // Owner ranges: today + last 7 days with net revenue (refunds off) and
        // AOV over completed orders. Refunds carry no date, so they attach to
        // the refunded order's creation window.
        $ranges = [];
        foreach (['today' => date('Y-m-d 00:00:00'), 'week' => date('Y-m-d H:i:s', strtotime('-7 days'))] as $name => $since) {
            $rangeOrders = $count($pdo, 'SELECT COUNT(*) FROM orders WHERE created_at>=?' . $scopeWhere, [$since, ...$scopeParams]);
            $rangeCompleted = $count($pdo, "SELECT COUNT(*) FROM orders WHERE state='completed' AND created_at>=?" . $scopeWhere, [$since, ...$scopeParams]);
            $rangeRevenue = $count($pdo, "SELECT COALESCE(SUM(grand_total_minor),0) FROM orders WHERE state='completed' AND created_at>=?" . $scopeWhere, [$since, ...$scopeParams]);
            $rangeRefunds = 0;
            try {
                $refundQuery = $pdo->prepare('SELECT COALESCE(SUM(r.amount_minor),0) FROM refunds r INNER JOIN orders o ON o.id=r.order_id WHERE o.created_at>=?' . str_replace('store_id = ?', 'o.store_id = ?', str_replace('channel_id = ?', 'o.channel_id = ?', $scopeWhere)));
                $refundQuery->execute([$since, ...$scopeParams]);
                $rangeRefunds = (int) $refundQuery->fetchColumn();
            } catch (\Throwable) {
                // Fresh installs without the refunds table keep reporting working.
            }
            $ranges[$name] = ['orders' => $rangeOrders, 'completed_orders' => $rangeCompleted, 'revenue_minor' => $rangeRevenue, 'refunds_minor' => $rangeRefunds, 'net_minor' => $rangeRevenue - $rangeRefunds, 'aov_minor' => $rangeCompleted > 0 ? (int) round($rangeRevenue / $rangeCompleted) : 0, 'since' => $since];
        }
        return $this->success([
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'total_orders' => $totalOrders,
            'total_products' => $totalProducts,
            'total_revenue' => $revenue,
            'recent_activity' => $activity,
            'api_status' => ['status' => 'healthy', 'version' => CommerceVersion::VERSION, 'uptime' => (int) (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))), 'response_time' => (int) round((microtime(true) - $started) * 1000)],
            'monthly_revenue' => $monthly,
            'orders_by_status' => $byStatus,
            'ranges' => $ranges,
            'scope' => $scope->metadata(),
            'freshness' => ['generated_at' => date(DATE_ATOM), 'max_age_seconds' => 30, 'status' => 'fresh'],
        ], 'Dashboard stats');
    }
}
