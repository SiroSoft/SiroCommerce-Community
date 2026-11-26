<?php

declare(strict_types=1);

namespace App\Modules\Smart\Application;

use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Http;

/**
 * Nightly rollups for no-model smart skills (spec SMART_SKILLS_SPEC §0).
 * Idempotent: same input → same rows, upserted by unique key.
 */
final class SmartAggregator
{
    /** @var (\Closure(array<string, mixed>): void)|null */
    private ?\Closure $webhook;

    /** @param (\Closure(array<string, mixed>): void)|null $webhook */
    public function __construct(?\Closure $webhook = null)
    {
        $this->webhook = $webhook;
    }

    /** @return array{affinities: int, terms: int, rollups: int, replenishment: int, anomalies: int} */
    public function aggregateAll(): array
    {
        return [
            'affinities' => $this->aggregateAffinities(),
            'terms' => $this->aggregateSearchTerms(),
            'rollups' => $this->aggregateReviewRollups(),
            'replenishment' => $this->aggregateReplenishment(),
            'anomalies' => $this->aggregateAnomalies(),
        ];
    }

    /**
     * Ops anomaly alerts (P4 ops intelligence, rules-based): refund, payment
     * and fulfillment failure spikes from commerce events in analytics_events.
     * A kind fires when the last 24h count is >= 3 AND >= 3x the prior-7-day
     * daily baseline (>= 5 when the baseline is below 1/day, so quiet stores
     * don't page on noise). One row per kind: open on fire, resolved when the
     * condition clears; an ack survives until the condition clears.
     *
     * @return int open alert count after this run
     */
    public function aggregateAnomalies(): int
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        $cut24h = date('Y-m-d H:i:s', time() - 24 * 3600);
        $cut8d = date('Y-m-d H:i:s', time() - 8 * 86400);
        $kinds = [
            'refund_spike' => ['refund.created', 'refund.succeeded'],
            'payment_fail_spike' => ['payment.failed'],
            'fulfillment_fail_spike' => ['fulfillment.failed'],
        ];
        $isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $upsert = $isMysql
            ? 'INSERT INTO ops_anomaly_alerts (id, kind, state, window_hours, observed, baseline_x100, ratio_x100, first_seen_at, last_seen_at, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), observed = VALUES(observed), baseline_x100 = VALUES(baseline_x100), ratio_x100 = VALUES(ratio_x100), last_seen_at = VALUES(last_seen_at), computed_at = VALUES(computed_at)'
            : 'INSERT INTO ops_anomaly_alerts (id, kind, state, window_hours, observed, baseline_x100, ratio_x100, first_seen_at, last_seen_at, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(kind) DO UPDATE SET state = excluded.state, observed = excluded.observed, baseline_x100 = excluded.baseline_x100, ratio_x100 = excluded.ratio_x100, last_seen_at = excluded.last_seen_at, computed_at = excluded.computed_at';
        $write = $pdo->prepare($upsert);
        $find = $pdo->prepare('SELECT id, state, first_seen_at FROM ops_anomaly_alerts WHERE kind = ? LIMIT 1');
        $count = $pdo->prepare('SELECT COUNT(*) FROM analytics_events WHERE event IN (?, ?, ?) AND created_at IS NOT NULL AND created_at >= ? AND created_at < ?');
        foreach ($kinds as $kind => $events) {
            $probe = array_values(array_pad($events, 3, $events[0]));
            $count->execute([...$probe, $cut24h, $now]);
            $observed = (int) $count->fetchColumn();
            $count->execute([...$probe, $cut8d, $cut24h]);
            $prior = (int) $count->fetchColumn();
            $baseline = $prior / 7;
            $fires = $observed >= 3 && ($baseline < 1 ? $observed >= 5 : $observed >= 3 * $baseline);
            $find->execute([$kind]);
            $existing = $find->fetch(\PDO::FETCH_ASSOC);
            $state = is_array($existing) ? (string) ($existing['state'] ?? 'open') : '';
            if ($fires) {
                $ratio = (int) round($observed / max($baseline, 1) * 100);
                $baselineX100 = (int) round($baseline * 100);
                $openedNow = false;
                if ($existing === false || !is_array($existing)) {
                    $write->execute([Ids::new(), $kind, 'open', 24, $observed, $baselineX100, $ratio, $now, $now, $now]);
                    $openedNow = true;
                } else {
                    // Acked alerts stay acked until the condition clears.
                    $next = $state === 'acked' ? 'acked' : 'open';
                    $write->execute([$existing['id'], $kind, $next, 24, $observed, $baselineX100, $ratio, $now, $now, $now]);
                    // Preserve the original first_seen_at on update.
                    $keep = $pdo->prepare('UPDATE ops_anomaly_alerts SET first_seen_at = ? WHERE kind = ? AND (first_seen_at IS NULL OR first_seen_at = ?)');
                    $keep->execute([is_string($existing['first_seen_at'] ?? null) ? $existing['first_seen_at'] : $now, $kind, $now]);
                    // Notify only on a transition into "open"; an already-open
                    // kind must not page again every hourly run.
                    $openedNow = $next === 'open' && $state !== 'open';
                }
                if ($openedNow) {
                    $this->notifyAnomalyOpened($kind, $observed, $baselineX100, $ratio);
                }
            } elseif (is_array($existing) && in_array($state, ['open', 'acked'], true)) {
                $resolve = $pdo->prepare("UPDATE ops_anomaly_alerts SET state = 'resolved', observed = ?, baseline_x100 = ?, computed_at = ? WHERE kind = ? AND state IN ('open', 'acked')");
                $resolve->execute([$observed, (int) round($baseline * 100), $now, $kind]);
            }
        }
        $stillOpen = $pdo->query("SELECT COUNT(*) FROM ops_anomaly_alerts WHERE state = 'open'")->fetchColumn();
        return (int) $stillOpen;
    }

    /**
     * Best-effort ops webhook for a freshly opened anomaly. Disabled unless
     * OPS_ALERT_WEBHOOK_URL is set. Never throws, and never logs the URL
     * because it can embed a bearer token.
     */
    private function notifyAnomalyOpened(string $kind, int $observed, int $baselineX100, int $ratioX100): void
    {
        $payload = [
            'kind' => $kind,
            'observed' => $observed,
            'baseline_x100' => $baselineX100,
            'ratio_x100' => $ratioX100,
            'at' => date('c'),
        ];
        try {
            if ($this->webhook !== null) {
                ($this->webhook)($payload);
                return;
            }
            $url = (string) Env::get('OPS_ALERT_WEBHOOK_URL', '');
            if ($url === '') {
                return;
            }
            Http::timeout(3);
            Http::post($url, $payload, ['Content-Type' => 'application/json']);
        } catch (\Throwable) {
            // Best-effort: a webhook failure must not break aggregation.
        }
    }

    /**
     * Demand forecast hints (P4 ops intelligence, rules-based): per-variant
     * 7/30-day velocity from non-cancelled orders, days of cover from live
     * availability, suggested top-up to a 14-day cover target. Idempotent
     * upsert by variant_id.
     */
    public function aggregateReplenishment(): int
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        $cut30 = date('Y-m-d H:i:s', time() - 30 * 86400);
        $cut7 = date('Y-m-d H:i:s', time() - 7 * 86400);
        $sales = $pdo->prepare("SELECT l.variant_id, l.qty, o.created_at FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE o.state NOT IN ('cancelled', 'failed') AND o.created_at IS NOT NULL AND o.created_at >= ?");
        $sales->execute([$cut30]);
        /** @var array<string, array{sold_7: int, sold_30: int}> $velocity */
        $velocity = [];
        foreach ($sales->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) continue;
            $variant = isset($row['variant_id']) && is_string($row['variant_id']) ? $row['variant_id'] : '';
            if ($variant === '') continue;
            $qty = (int) ($row['qty'] ?? 0);
            if ($qty <= 0) continue;
            $velocity[$variant] ??= ['sold_7' => 0, 'sold_30' => 0];
            $velocity[$variant]['sold_30'] += $qty;
            if (is_string($row['created_at'] ?? null) && $row['created_at'] >= $cut7) $velocity[$variant]['sold_7'] += $qty;
        }
        $stock = $pdo->query('SELECT variant_id, COALESCE(SUM(on_hand), 0) AS on_hand, COALESCE(SUM(on_hand - reserved), 0) AS available FROM inventory_items GROUP BY variant_id')->fetchAll(\PDO::FETCH_ASSOC);
        /** @var array<string, array{on_hand: int, available: int}> $levels */
        $levels = [];
        foreach ($stock as $row) {
            if (!is_array($row)) continue;
            $variant = isset($row['variant_id']) && is_string($row['variant_id']) ? $row['variant_id'] : '';
            if ($variant === '') continue;
            $levels[$variant] = ['on_hand' => max(0, (int) ($row['on_hand'] ?? 0)), 'available' => max(0, (int) ($row['available'] ?? 0))];
        }
        $variants = array_unique(array_merge(array_keys($velocity), array_keys($levels)));
        if ($variants === []) return 0;
        /** @var array<string, string> $productByVariant */
        $productByVariant = [];
        foreach (array_chunk($variants, 900) as $variantChunk) {
            $placeholders = implode(', ', array_fill(0, count($variantChunk), '?'));
            $rows = Database::select('SELECT id, product_id FROM product_variants WHERE id IN (' . $placeholders . ')', $variantChunk);
            foreach ($rows as $row) {
                $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
                $productId = isset($row['product_id']) && is_string($row['product_id']) ? $row['product_id'] : '';
                if ($id !== '' && $productId !== '') $productByVariant[$id] = $productId;
            }
        }
        $isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO replenishment_hints (id, variant_id, product_id, sold_7, sold_30, on_hand, available, days_of_cover, suggested_qty, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), sold_7 = VALUES(sold_7), sold_30 = VALUES(sold_30), on_hand = VALUES(on_hand), available = VALUES(available), days_of_cover = VALUES(days_of_cover), suggested_qty = VALUES(suggested_qty), computed_at = VALUES(computed_at)'
            : 'INSERT INTO replenishment_hints (id, variant_id, product_id, sold_7, sold_30, on_hand, available, days_of_cover, suggested_qty, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(variant_id) DO UPDATE SET product_id = excluded.product_id, sold_7 = excluded.sold_7, sold_30 = excluded.sold_30, on_hand = excluded.on_hand, available = excluded.available, days_of_cover = excluded.days_of_cover, suggested_qty = excluded.suggested_qty, computed_at = excluded.computed_at';
        $stmt = $pdo->prepare($sql);
        $written = 0;
        foreach ($variants as $variant) {
            $sold7 = $velocity[$variant]['sold_7'] ?? 0;
            $sold30 = $velocity[$variant]['sold_30'] ?? 0;
            $onHand = $levels[$variant]['on_hand'] ?? 0;
            $available = $levels[$variant]['available'] ?? 0;
            $perDay = $sold30 / 30;
            $cover = $perDay > 0 ? (int) floor($available / $perDay) : null;
            $suggested = $perDay > 0 ? max(0, (int) ceil($perDay * 14) - $available) : 0;
            $stmt->execute([Ids::new(), $variant, $productByVariant[$variant] ?? null, $sold7, $sold30, $onHand, $available, $cover, $suggested, $now]);
            $written++;
        }
        return $written;
    }

    public function aggregateAffinities(): int
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        // One bounded read instead of a per-order line query (no N+1).
        $rows = Database::select("SELECT l.order_id, l.variant_id FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE o.state NOT IN ('cancelled', 'failed')");
        /** @var array<string, array<string, true>> $variantsByOrder */
        $variantsByOrder = [];
        foreach ($rows as $row) {
            $orderId = isset($row['order_id']) && is_string($row['order_id']) ? $row['order_id'] : '';
            $variant = isset($row['variant_id']) && is_string($row['variant_id']) ? $row['variant_id'] : '';
            if ($orderId === '' || $variant === '') continue;
            $variantsByOrder[$orderId][$variant] = true;
        }
        $pairs = [];
        foreach ($variantsByOrder as $orderVariants) {
            $variants = array_keys($orderVariants);
            sort($variants);
            $count = count($variants);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = $variants[$i] . '|' . $variants[$j];
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }
        $written = 0;
        $isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO smart_affinities (id, product_a, product_b, orders, score, computed_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE orders = VALUES(orders), score = VALUES(score), computed_at = VALUES(computed_at)'
            : 'INSERT INTO smart_affinities (id, product_a, product_b, orders, score, computed_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(product_a, product_b) DO UPDATE SET orders = excluded.orders, score = excluded.score, computed_at = excluded.computed_at';
        $stmt = $pdo->prepare($sql);
        foreach ($pairs as $key => $ordersCount) {
            [$a, $b] = explode('|', $key, 2);
            if ($ordersCount < 1) continue;
            $stmt->execute([Ids::new(), $a, $b, $ordersCount, $ordersCount, $now]);
            $written++;
        }
        return $written;
    }

    public function aggregateSearchTerms(): int
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        $rows = $pdo->query("SELECT event, payload_json FROM analytics_events WHERE event IN ('search_submit', 'search_click')")->fetchAll(\PDO::FETCH_ASSOC);
        $terms = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $event = (string) ($row['event'] ?? '');
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
            $props = is_array($payload) ? ($payload['properties'] ?? []) : [];
            if (!is_array($props)) continue;
            $query = isset($props['query']) && is_string($props['query']) ? SmartQuery::normalize($props['query']) : null;
            if ($query === null) continue;
            $terms[$query] ??= ['submits' => 0, 'clicks' => 0];
            if ($event === 'search_click') $terms[$query]['clicks']++;
            else $terms[$query]['submits']++;
        }
        $written = 0;
        $isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO smart_search_terms (id, query, submits, clicks, computed_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE submits = VALUES(submits), clicks = VALUES(clicks), computed_at = VALUES(computed_at)'
            : 'INSERT INTO smart_search_terms (id, query, submits, clicks, computed_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(query) DO UPDATE SET submits = excluded.submits, clicks = excluded.clicks, computed_at = excluded.computed_at';
        $stmt = $pdo->prepare($sql);
        foreach ($terms as $query => $counts) {
            $stmt->execute([Ids::new(), $query, $counts['submits'], $counts['clicks'], $now]);
            $written++;
        }
        return $written;
    }

    public function aggregateReviewRollups(): int
    {
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        $reviews = $pdo->query("SELECT product_id, rating, body FROM product_reviews WHERE status = 'approved'")->fetchAll(\PDO::FETCH_ASSOC);
        $byProduct = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) continue;
            $pid = isset($review['product_id']) && is_string($review['product_id']) ? $review['product_id'] : '';
            if ($pid === '') continue;
            $byProduct[$pid][] = ['rating' => (int) ($review['rating'] ?? 0), 'body' => (string) ($review['body'] ?? '')];
        }
        $scorer = new SmartLexiconScorer();
        $written = 0;
        $isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO smart_review_rollups (id, product_id, rating_avg, rating_count, pos_count, neg_count, aspects_json, excerpts_json, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating_avg = VALUES(rating_avg), rating_count = VALUES(rating_count), pos_count = VALUES(pos_count), neg_count = VALUES(neg_count), aspects_json = VALUES(aspects_json), excerpts_json = VALUES(excerpts_json), computed_at = VALUES(computed_at)'
            : 'INSERT INTO smart_review_rollups (id, product_id, rating_avg, rating_count, pos_count, neg_count, aspects_json, excerpts_json, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(product_id) DO UPDATE SET rating_avg = excluded.rating_avg, rating_count = excluded.rating_count, pos_count = excluded.pos_count, neg_count = excluded.neg_count, aspects_json = excluded.aspects_json, excerpts_json = excluded.excerpts_json, computed_at = excluded.computed_at';
        $stmt = $pdo->prepare($sql);
        foreach ($byProduct as $pid => $items) {
            $rollup = $scorer->rollup($items);
            $row = [$pid, $rollup['rating_avg'], count($items), $rollup['pos_count'], $rollup['neg_count'], json_encode($rollup['aspects'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($rollup['excerpts'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now];
            $stmt->execute([Ids::new(), ...$row]);
            $written++;
        }
        return $written;
    }
}
