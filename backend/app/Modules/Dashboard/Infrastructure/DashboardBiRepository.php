<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Infrastructure;

use App\Modules\Dashboard\Contracts\DashboardBiReader;
use App\Support\Arr;
use App\Support\CommerceScope;
use Siro\Core\Database;

/**
 * Bounded read-model queries for the dashboard. Every public read is
 * fail-closed: a missing table (fresh install) or provider error returns an
 * empty aggregate instead of bubbling a 500 to the dashboard.
 */
final class DashboardBiRepository implements DashboardBiReader
{
    private const EXCLUDED_ORDER_STATES = ['cancelled', 'failed'];
    private const LOW_STOCK_THRESHOLD = 5;

    public function sales(string $from, string $to, CommerceScope $scope): array
    {
        $daily = $this->rows(
            'SELECT currency, date(created_at) AS day, COUNT(*) AS orders, COALESCE(SUM(grand_total_minor), 0) AS gross_minor'
            . ' FROM orders WHERE created_at >= ? AND created_at <= ? AND state NOT IN (?, ?) AND store_id = ? AND channel_id = ?'
            . ' GROUP BY currency, date(created_at) ORDER BY currency ASC, day ASC',
            [$from, $to, ...self::EXCLUDED_ORDER_STATES, $scope->storeId, $scope->channelId],
        );
        $refunds = $this->rows(
            'SELECT r.currency AS currency, COALESCE(SUM(r.amount_minor), 0) AS refunds_minor'
            . ' FROM refunds r INNER JOIN orders o ON o.id = r.order_id'
            . ' WHERE r.created_at >= ? AND r.created_at <= ? AND o.store_id = ? AND o.channel_id = ?'
            . ' GROUP BY r.currency',
            [$from, $to, $scope->storeId, $scope->channelId],
        );

        /** @var array<string, array<string, array{gross_minor: int, orders: int}>> $series */
        $series = [];
        foreach ($daily as $row) {
            $currency = strtoupper(Arr::str($row, 'currency', 'UNKNOWN'));
            $day = substr(Arr::str($row, 'day'), 0, 10);
            if ($day === '') continue;
            $series[$currency][$day] = ['gross_minor' => Arr::int($row, 'gross_minor'), 'orders' => Arr::int($row, 'orders')];
        }
        /** @var array<string, int> $refundsByCurrency */
        $refundsByCurrency = [];
        foreach ($refunds as $row) {
            $currency = strtoupper(Arr::str($row, 'currency', 'UNKNOWN'));
            $refundsByCurrency[$currency] = ($refundsByCurrency[$currency] ?? 0) + Arr::int($row, 'refunds_minor');
        }

        $currencies = array_values(array_unique([...array_keys($series), ...array_keys($refundsByCurrency)]));
        sort($currencies);
        $blocks = [];
        foreach ($currencies as $currency) {
            $gross = 0;
            $orders = 0;
            $byDay = [];
            $first = new \DateTimeImmutable(substr($from, 0, 10));
            $last = new \DateTimeImmutable(substr($to, 0, 10));
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $bucket = $series[$currency][$date] ?? ['gross_minor' => 0, 'orders' => 0];
                $gross += $bucket['gross_minor'];
                $orders += $bucket['orders'];
                $byDay[] = ['date' => $date, 'gross_minor' => $bucket['gross_minor'], 'orders' => $bucket['orders']];
            }
            $blocks[] = [
                'currency' => $currency,
                'gross_minor' => $gross,
                'orders' => $orders,
                'aov_minor' => $orders > 0 ? (int) round($gross / $orders) : 0,
                'refunds_minor' => $refundsByCurrency[$currency] ?? 0,
                'by_day' => $byDay,
            ];
        }
        return $blocks;
    }

    public function funnel(string $from, string $to, CommerceScope $scope): array
    {
        $params = [$from, $to, $scope->storeId, $scope->channelId];
        $sessionsRow = $this->rows(
            "SELECT COUNT(DISTINCT session_key) AS sessions FROM analytics_events"
            . " WHERE created_at >= ? AND created_at <= ? AND store_id = ? AND channel_id = ? AND session_key IS NOT NULL AND session_key <> ''",
            $params,
        );
        $counts = ['sessions' => Arr::int($sessionsRow[0] ?? [], 'sessions'), 'product_views' => 0, 'add_to_cart' => 0, 'checkout_started' => 0, 'orders' => 0];
        $rows = $this->rows(
            'SELECT event, COUNT(*) AS event_count FROM analytics_events'
            . ' WHERE created_at >= ? AND created_at <= ? AND store_id = ? AND channel_id = ? GROUP BY event',
            $params,
        );
        foreach ($rows as $row) {
            $event = Arr::str($row, 'event');
            $count = Arr::int($row, 'event_count');
            match (true) {
                in_array($event, ['product_view', 'product.view'], true) => $counts['product_views'] += $count,
                in_array($event, ['cart.add', 'cart_add'], true) => $counts['add_to_cart'] += $count,
                in_array($event, ['checkout_started', 'checkout.start'], true) => $counts['checkout_started'] += $count,
                $event === 'order.placed' => $counts['orders'] += $count,
                default => null,
            };
        }
        return $counts;
    }

    public function topProducts(string $from, string $to, CommerceScope $scope): array
    {
        $rows = $this->rows(
            'SELECT COALESCE(pv.product_id, ol.variant_id) AS product_id,'
            . " COALESCE(MAX(p.name), MAX(ol.name), '') AS name, COALESCE(MAX(p.slug), '') AS slug,"
            . ' COALESCE(SUM(ol.qty), 0) AS qty, COALESCE(SUM(ol.qty * ol.unit_price_minor), 0) AS revenue_minor'
            . ' FROM order_lines ol INNER JOIN orders o ON o.id = ol.order_id'
            . ' LEFT JOIN product_variants pv ON pv.id = ol.variant_id LEFT JOIN products p ON p.id = pv.product_id'
            . ' WHERE o.created_at >= ? AND o.created_at <= ? AND o.state NOT IN (?, ?) AND o.store_id = ? AND o.channel_id = ?'
            . ' GROUP BY COALESCE(pv.product_id, ol.variant_id)'
            . ' ORDER BY qty DESC, revenue_minor DESC LIMIT 5',
            [$from, $to, ...self::EXCLUDED_ORDER_STATES, $scope->storeId, $scope->channelId],
        );
        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'product_id' => Arr::str($row, 'product_id'),
                'name' => Arr::str($row, 'name'),
                'slug' => Arr::str($row, 'slug'),
                'qty' => Arr::int($row, 'qty'),
                'revenue_minor' => Arr::int($row, 'revenue_minor'),
            ];
        }
        return $products;
    }

    public function lowStock(CommerceScope $scope): array
    {
        $rows = $this->rows(
            'SELECT ii.variant_id AS variant_id, COALESCE(MAX(p.name), MAX(pv.sku), ii.variant_id) AS name,'
            . " COALESCE(MAX(pv.sku), '') AS sku, COALESCE(SUM(ii.on_hand - ii.reserved), 0) AS available"
            . ' FROM inventory_items ii INNER JOIN product_variants pv ON pv.id = ii.variant_id'
            . ' INNER JOIN products p ON p.id = pv.product_id'
            . ' WHERE p.store_id = ? AND p.channel_id = ?'
            . ' GROUP BY ii.variant_id'
            . ' HAVING SUM(ii.on_hand - ii.reserved) <= ?'
            . ' ORDER BY SUM(ii.on_hand - ii.reserved) ASC, ii.variant_id ASC LIMIT 10',
            [$scope->storeId, $scope->channelId, self::LOW_STOCK_THRESHOLD],
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'variant_id' => Arr::str($row, 'variant_id'),
                'name' => Arr::str($row, 'name'),
                'sku' => Arr::str($row, 'sku'),
                'available' => Arr::int($row, 'available'),
            ];
        }
        return $items;
    }

    public function ops(CommerceScope $scope): array
    {
        return [
            'failed_payments' => $this->scalar(
                'SELECT COUNT(*) FROM payment_operations po INNER JOIN payments p ON p.id = po.payment_id'
                . ' INNER JOIN orders o ON o.id = p.order_id'
                . " WHERE po.state IN ('failed', 'unknown') AND o.store_id = ? AND o.channel_id = ?",
                [$scope->storeId, $scope->channelId],
            ),
            'pending_refunds' => $this->scalar(
                'SELECT COUNT(*) FROM refunds r INNER JOIN orders o ON o.id = r.order_id'
                . " WHERE r.state IN ('pending', 'processing') AND o.store_id = ? AND o.channel_id = ?",
                [$scope->storeId, $scope->channelId],
            ),
            'queue_dead_letters' => $this->scalar(
                'SELECT COUNT(*) FROM commerce_dead_letters dl INNER JOIN commerce_jobs j ON j.id = dl.job_id'
                . ' WHERE dl.resolved_at IS NULL AND j.store_id = ? AND j.channel_id = ?',
                [$scope->storeId, $scope->channelId],
            ),
            'open_ops_alerts' => $this->scalar("SELECT COUNT(*) FROM ops_anomaly_alerts WHERE state = 'open'", []),
        ];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        try {
            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $columns = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) $columns[$key] = $value;
            }
            $normalized[] = $columns;
        }
        return $normalized;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        try {
            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            return (int) $statement->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
