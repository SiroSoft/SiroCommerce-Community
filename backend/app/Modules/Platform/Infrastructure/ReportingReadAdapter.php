<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\CommerceJob;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentOperation;
use App\Models\Product;
use App\Models\Refund;
use App\Models\AnalyticsEvent;
use App\Support\Arr;
use App\Modules\Reporting\Contracts\ReportingReader;
use Siro\Core\Cache;
use Siro\Core\DB\QueryBuilder;
use App\Modules\Search\Contracts\SearchAdapter;

/** Read-side reporting and bounded staff search. No domain mutation or PII payloads. */
final class ReportingReadAdapter implements ReportingReader
{
    private const MAX_PAGE = 100;
    private const MAX_WINDOW_DAYS = 366;

    public function __construct(private readonly ?SearchAdapter $search = null) {}

    /** @return array<string, mixed> */
    public function overview(?string $from, ?string $to, ?string $storeId = null, ?string $channelId = null): array
    {
        [$start, $end] = $this->window($from, $to);
        $key = 'admin:report:overview:' . $start . ':' . $end . ':' . ($storeId ?? '-') . ':' . ($channelId ?? '-');
        /** @var array<string, mixed> $report */
        $report = Cache::remember($key, 30, function () use ($start, $end, $storeId, $channelId): array {
            $orders = Order::query()->where('created_at', '>=', $start)->where('created_at', '<=', $end);
            if ($storeId !== null) $orders->where('store_id', $storeId);
            if ($channelId !== null) $orders->where('channel_id', $channelId);
            $stateQuery = Order::query()->selectRaw('state, COUNT(*) AS count')->where('created_at', '>=', $start)->where('created_at', '<=', $end);
            if ($storeId !== null) $stateQuery->where('store_id', $storeId);
            if ($channelId !== null) $stateQuery->where('channel_id', $channelId);
            $states = $stateQuery->groupBy('state')->limit(32);
            if ($storeId === null && $channelId === null) $states->cache(30);
            $states = $states->get();
            $byState = [];
            foreach ($states as $row) {
                $data = Arr::row($row);
                $byState[Arr::str($data, 'state', 'unknown')] = Arr::int($data, 'count');
            }
             $salesByCurrency = $this->salesByCurrency($start, $end, $storeId, $channelId);
             $refundsByCurrency = $this->refundsByCurrency($start, $end, $storeId, $channelId);
             return [
                 'window' => ['from' => $start, 'to' => $end],
                 'orders' => ($storeId === null && $channelId === null ? $orders->cache(30) : $orders)->count(),
                 'sales_minor' => count($salesByCurrency) === 1 ? (int) reset($salesByCurrency) : null,
                 'refunds_minor' => count($refundsByCurrency) === 1 ? (int) reset($refundsByCurrency) : null,
                 'sales_minor_by_currency' => $salesByCurrency,
                 'refunds_minor_by_currency' => $refundsByCurrency,
                'by_state' => $byState,
                'inventory' => [
                    'items' => $this->inventoryCount($storeId, $channelId),
                    'low_stock' => $this->inventoryCount($storeId, $channelId, true),
                ],
                'failures' => $this->failureCounts(),
            ];
        });
        return $report;
    }

    /** @return array<string, int> */
    private function salesByCurrency(string $start, string $end, ?string $storeId, ?string $channelId): array
    {
        $query = Order::query()
            ->selectRaw('currency, COUNT(*) AS total_orders, SUM(grand_total_minor) AS revenue_minor')
            ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
            ->whereNotIn('state', ['cancelled', 'failed'])
            ->groupBy('currency');
        if ($storeId !== null) $query->where('store_id', $storeId);
        if ($channelId !== null) $query->where('channel_id', $channelId);
        if ($storeId === null && $channelId === null) $query->cache(30);
        $totals = [];
        foreach ($query->get() as $row) {
            $data = Arr::row($row);
            $currency = strtoupper(Arr::str($data, 'currency', 'UNKNOWN'));
            $totals[$currency] = ($totals[$currency] ?? 0) + Arr::int($data, 'revenue_minor');
        }
        ksort($totals);
        return $totals;
    }

    /** @return array<string, int> */
    private function refundsByCurrency(string $start, string $end, ?string $storeId, ?string $channelId): array
    {
        $query = Refund::query()
            ->selectRaw('currency, SUM(amount_minor) AS revenue_minor')
            ->where('created_at', '>=', $start)->where('created_at', '<=', $end)
            ->groupBy('currency');
        if ($storeId !== null) $query->whereRaw('order_id IN (SELECT id FROM orders WHERE store_id = :refund_scope_store)', ['refund_scope_store' => $storeId]);
        if ($channelId !== null) $query->whereRaw('order_id IN (SELECT id FROM orders WHERE channel_id = :refund_scope_channel)', ['refund_scope_channel' => $channelId]);
        if ($storeId === null && $channelId === null) $query->cache(30);
        $totals = [];
        foreach ($query->get() as $row) {
            $data = Arr::row($row);
            $currency = strtoupper(Arr::str($data, 'currency', 'UNKNOWN'));
            $totals[$currency] = ($totals[$currency] ?? 0) + Arr::int($data, 'revenue_minor');
        }
        ksort($totals);
        return $totals;
    }

    /** @return array<string, mixed> */
    public function failures(int $page, int $perPage): array
    {
        $perPage = min(max(1, $perPage), self::MAX_PAGE);
        $result = CommerceJob::query()->select(['id', 'queue', 'name', 'state', 'attempts', 'max_attempts', 'available_at'])
            ->whereIn('state', ['failed', 'dead'])->orderBy('available_at', 'desc')->orderBy('id', 'desc')->cache(15)->paginate($perPage, max(1, $page));
        $data = [];
        $resultRows = is_array($result['data'] ?? null) ? $result['data'] : [];
        foreach ($resultRows as $row) $data[] = Arr::row($row);
        /** @var array<string, mixed> $meta */
        $meta = $result['meta'];
        return ['data' => $data, 'meta' => $meta];
    }

    /** @return array<string, mixed> */
    public function search(string $query, string $type, int $page, int $perPage, ?string $storeId = null, ?string $channelId = null, ?string $locale = null, ?string $currency = null, array $facets = []): array
    {
        if ($this->search !== null) {
            /** @var array<string, mixed> $searchResult */
            $searchResult = $this->search->search(['q' => $query, 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'store_id' => $storeId, 'channel_id' => $channelId, 'locale' => $locale, 'currency' => $currency, 'facets' => $facets]);
            return $searchResult;
        }
        $term = trim($query);
        if ($term === '') return ['data' => [], 'meta' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1], 'type' => $type];
        $perPage = min(max(1, $perPage), self::MAX_PAGE);
        $page = max(1, $page);
        $type = in_array($type, ['catalog', 'orders', 'customers'], true) ? $type : 'catalog';
        $like = '%' . substr($term, 0, 80) . '%';
        if ($type === 'catalog') $queryBuilder = Product::query()->select(['id', 'name', 'slug', 'status'])->where('name', 'LIKE', $like)->where('store_id', $storeId)->where('channel_id', $channelId);
        elseif ($type === 'orders') $queryBuilder = Order::query()->select(['id', 'number', 'state', 'grand_total_minor', 'currency', 'created_at'])->where('number', 'LIKE', $like)->where('store_id', $storeId)->where('channel_id', $channelId);
        else $queryBuilder = Customer::query()->select(['id', 'name', 'email', 'created_at'])->whereRaw('(email LIKE ? OR name LIKE ?)', [$like, $like])->whereRaw('id IN (SELECT customer_id FROM orders WHERE store_id = ? AND channel_id = ?)', [$storeId, $channelId]);
        $result = $queryBuilder->orderBy('id')->cache(20)->paginate($perPage, $page);
        $rows = [];
        $resultRows = is_array($result['data'] ?? null) ? $result['data'] : [];
        foreach ($resultRows as $row) {
            $data = Arr::row($row);
            if ($type === 'customers') {
                $rows[] = ['id' => Arr::str($data, 'id'), 'name' => Arr::str($data, 'name'), 'email_masked' => $this->maskEmail(Arr::str($data, 'email'))];
            } else $rows[] = $data;
        }
        return ['data' => $rows, 'meta' => $result['meta'], 'type' => $type];
    }

    /**
     * Advanced reporting is deliberately evidence-first. A dimension is either
     * calculated from persisted facts or returned as unavailable with its reason.
     * @return array<string, mixed>
     */
    public function advanced(string $dimension, string $from, string $to, ?string $storeId = null, ?string $channelId = null): array
    {
        // Include the bounded source count so a cache entry cannot outlive an append-only snapshot.
        $sourceCount = $this->scopedOrders($from, $to, $storeId, $channelId)->count();
        $key = 'admin:report:advanced:v2:' . $dimension . ':' . $from . ':' . $to . ':' . ($storeId ?? '-') . ':' . ($channelId ?? '-') . ':' . $sourceCount;
        /** @var array<string, mixed> $result */
        $result = Cache::remember($key, 30, function () use ($dimension, $from, $to, $storeId, $channelId, $sourceCount): array {
            // Dimensions aggregate in SQL. Nothing loads the whole order set into PHP.
            $data = match ($dimension) {
                'profitability' => $this->profitabilityFromQuery($from, $to, $storeId, $channelId),
                'cohorts' => $this->cohortsFromQuery($from, $to, $storeId, $channelId),
                'retention' => $this->retentionFromQuery($from, $to, $storeId, $channelId),
                'repeat-purchase' => $this->repeatPurchaseFromQuery($from, $to, $storeId, $channelId),
                'attribution' => $this->attribution($from, $to, $storeId, $channelId),
                'sla' => $this->sla($sourceCount),
                'inventory-turnover' => $this->inventoryTurnoverFromQuery($from, $to, $storeId, $channelId),
                'widgets' => $this->widgetsFromQuery($from, $to, $storeId, $channelId),
                'insights' => $this->insightsFromQuery($from, $to, $storeId, $channelId, $sourceCount),
                default => ['status' => 'unavailable', 'reason' => 'Unsupported reporting dimension'],
            };
            $result = ['schema_version' => 1, 'dimension' => $dimension, 'window' => ['from' => $from, 'to' => $to], 'scope' => ['store_id' => $storeId, 'channel_id' => $channelId], 'data' => $data];
            $result['snapshot'] = ['id' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)), 'version' => 1, 'captured_at' => gmdate('c'), 'consistency' => 'bounded-read-cache'];
            return $result;
        });
        return $result;
    }

    private function scopedOrders(string $from, string $to, ?string $storeId, ?string $channelId): QueryBuilder
    {
        $query = Order::query()->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        if ($storeId !== null) $query->where('store_id', $storeId);
        if ($channelId !== null) $query->where('channel_id', $channelId);
        return $query;
    }

    /** @return array<string, mixed> */
    private function profitabilityFromQuery(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        $row = Arr::row($this->scopedOrders($from, $to, $storeId, $channelId)
            ->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(grand_total_minor), 0) AS revenue_minor, COALESCE(SUM(discount_minor), 0) AS discount_minor')
            ->first() ?? []);
        return ['status' => 'partial', 'orders' => Arr::int($row, 'orders_count'), 'revenue_minor' => Arr::int($row, 'revenue_minor'), 'discount_minor' => Arr::int($row, 'discount_minor'), 'refunds_minor' => null, 'cogs_minor' => null, 'gross_margin_minor' => null, 'gross_margin_percent' => null, 'unavailable' => ['cogs' => 'No immutable cost basis is persisted on products, variants, order lines, or inventory receipts; margin is not inferred.']];
    }

    /** @return array<string, mixed> */
    private function cohortsFromQuery(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        $cohorts = [];
        $rows = $this->scopedOrders($from, $to, $storeId, $channelId)
            ->selectRaw('customer_id, MIN(created_at) AS first_at, SUM(grand_total_minor) AS spend_minor')
            ->whereRaw("customer_id IS NOT NULL AND customer_id <> ''")
            ->groupBy('customer_id')->get();
        foreach ($rows as $row) {
            $data = Arr::row($row);
            $month = substr(Arr::dt($data, 'first_at'), 0, 7);
            if ($month === '') continue;
            $cohorts[$month] ??= ['cohort' => $month, 'customers' => 0, 'revenue_minor' => 0];
            $cohorts[$month]['customers']++;
            $cohorts[$month]['revenue_minor'] += Arr::int($data, 'spend_minor');
        }
        ksort($cohorts);
        return ['status' => 'partial', 'rows' => array_values($cohorts), 'unavailable' => ['ltv' => 'Lifetime horizon and acquisition cost are not persisted; cohort revenue is a bounded observed value.', 'guest_customers' => 'Guest orders have no stable customer identity and are excluded.']];
    }

    /** @return array<string, mixed> */
    private function retentionFromQuery(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        $row = Arr::row($this->scopedOrders($from, $to, $storeId, $channelId)
            ->selectRaw('COUNT(DISTINCT customer_id) AS observed_customers')
            ->whereRaw("customer_id IS NOT NULL AND customer_id <> ''")
            ->first() ?? []);
        return ['status' => 'partial', 'observed_customers' => Arr::int($row, 'observed_customers'), 'retained_customers' => null, 'retention_percent' => null, 'unavailable' => ['retention' => 'A follow-up horizon requires data beyond the requested snapshot; only bounded order facts are available.']];
    }

    /** @return array<string, mixed> */
    private function repeatPurchaseFromQuery(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        $identified = 0; $repeat = 0;
        foreach ($this->scopedOrders($from, $to, $storeId, $channelId)
            ->selectRaw('customer_id, COUNT(*) AS order_count')
            ->whereRaw("customer_id IS NOT NULL AND customer_id <> ''")
            ->groupBy('customer_id')->get() as $row) {
            $identified++;
            if (Arr::int(Arr::row($row), 'order_count') > 1) $repeat++;
        }
        return ['status' => 'observed', 'identified_customers' => $identified, 'repeat_customers' => $repeat, 'repeat_rate_percent' => $identified === 0 ? null : round($repeat * 100 / $identified, 2), 'churned_customers' => null, 'unavailable' => ['churn' => 'Churn requires a defined inactivity horizon and post-window observations.']];
    }

    /** @return array<string, mixed> */
    private function attribution(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        $query = AnalyticsEvent::query()->selectRaw('event, COUNT(*) AS event_count')->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        if ($storeId !== null) $query->where('store_id', $storeId); if ($channelId !== null) $query->where('channel_id', $channelId);
        $events = [];
        foreach ($query->groupBy('event')->get() as $row) {
            $data = Arr::row($row);
            $event = Arr::str($data, 'event', 'unknown');
            $events[$event] = ($events[$event] ?? 0) + Arr::int($data, 'event_count');
        }
        ksort($events);
        return ['status' => 'partial', 'events_by_type' => $events, 'attribution' => [], 'unavailable' => ['source_attribution' => 'No trusted acquisition-touch/source field is persisted in the analytics contract; event counts are not attributed.']];
    }

    /** @return array<string, mixed> */
    private function sla(int $ordersObserved): array
    {
        return ['status' => 'unavailable', 'orders_observed' => $ordersObserved, 'failure_counts' => $this->failureCounts(), 'sla' => [], 'unavailable' => ['fulfillment_sla' => 'Fulfillment completion timestamps and target policies are not persisted.', 'payment_sla' => 'Payment attempt latency is not persisted as a duration.']];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    private function inventoryTurnover(array $orders, ?string $storeId = null, ?string $channelId = null): array
    {
        $orderIds = [];
        foreach ($orders as $order) {
            $id = Arr::str($order, 'id');
            if ($id !== '') $orderIds[] = $id;
        }
        $units = 0;
        foreach (array_chunk($orderIds, 500) as $chunk) {
            foreach (OrderLine::query()->select(['order_id', 'qty'])->whereIn('order_id', $chunk)->get() as $line) {
                $units += Arr::int(Arr::row($line), 'qty');
            }
        }
        return ['status' => 'partial', 'units_sold' => $units, 'on_hand_units' => $this->inventoryQuery($storeId, $channelId)->sum('on_hand'), 'turnover' => null, 'unavailable' => ['turnover' => 'Historical average inventory value and inventory cost are not persisted; units sold is evidence only.']];
    }

    /** @return array<string, mixed> */
    private function inventoryTurnoverFromQuery(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        // Stream id-only rows in bounded pages (never the full order row) and
        // let inventoryTurnover batch the order-line reads by chunk.
        $orders = [];
        $lastId = null;
        do {
            $query = $this->scopedOrders($from, $to, $storeId, $channelId)->select(['id'])->orderBy('id');
            if ($lastId !== null) $query->where('id', '>', $lastId);
            $rows = $query->limit(500)->get();
            foreach ($rows as $row) {
                $id = Arr::str(Arr::row($row), 'id');
                if ($id === '') continue;
                $lastId = $id;
                $orders[] = ['id' => $id];
            }
        } while (count($rows) === 500);
        return $this->inventoryTurnover($orders, $storeId, $channelId);
    }

    /** @return array<string, mixed> */
    private function widgetsFromQuery(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        $row = Arr::row($this->scopedOrders($from, $to, $storeId, $channelId)
            ->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(grand_total_minor), 0) AS revenue_minor')
            ->first() ?? []);
        return ['status' => 'observed', 'available' => ['revenue', 'orders', 'failures', 'inventory'], 'widgets' => ['revenue' => ['value_minor' => Arr::int($row, 'revenue_minor')], 'orders' => ['value' => Arr::int($row, 'orders_count')], 'failures' => ['value' => $this->failureCounts()], 'inventory' => ['value' => ['items' => $this->inventoryCount($storeId, $channelId), 'low_stock' => $this->inventoryCount($storeId, $channelId, true)]]], 'extension' => ['registry_version' => 1, 'unknown_widgets' => 'return unavailable rather than executing arbitrary queries']];
    }

    /**
     * Merchant insights deliberately keep money partitioned by currency. Orders and
     * refunds are authoritative records; event and product signals are estimates.
     * @return array<string, mixed>
     */
    private function insightsFromQuery(string $from, string $to, ?string $storeId, ?string $channelId, int $ordersCount): array
    {
        $sales = [];
        foreach ($this->scopedOrders($from, $to, $storeId, $channelId)
            ->selectRaw('currency, SUM(grand_total_minor) AS revenue_minor')
            ->groupBy('currency')->get() as $row) {
            $data = Arr::row($row);
            $currency = strtoupper(Arr::str($data, 'currency', 'UNKNOWN'));
            $sales[$currency] = ($sales[$currency] ?? 0) + Arr::int($data, 'revenue_minor');
        }

        $identified = 0; $repeat = 0;
        foreach ($this->scopedOrders($from, $to, $storeId, $channelId)
            ->selectRaw('customer_id, COUNT(*) AS order_count')
            ->whereRaw("customer_id IS NOT NULL AND customer_id <> ''")
            ->groupBy('customer_id')->get() as $row) {
            $identified++;
            if (Arr::int(Arr::row($row), 'order_count') > 1) $repeat++;
        }

        $refunds = [];
        $refundQuery = Refund::query()
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->selectRaw('refunds.currency AS currency, SUM(refunds.amount_minor) AS revenue_minor')
            ->where('orders.created_at', '>=', $from)->where('orders.created_at', '<=', $to)
            ->where('refunds.created_at', '>=', $from)->where('refunds.created_at', '<=', $to);
        if ($storeId !== null) $refundQuery->where('orders.store_id', $storeId);
        if ($channelId !== null) $refundQuery->where('orders.channel_id', $channelId);
        foreach ($refundQuery->groupBy('refunds.currency')->get() as $row) {
            $data = Arr::row($row);
            $currency = strtoupper(Arr::str($data, 'currency', 'UNKNOWN'));
            $refunds[$currency] = ($refunds[$currency] ?? 0) + Arr::int($data, 'revenue_minor');
        }

        $events = $this->scopedEvents($from, $to, $storeId, $channelId);
        $funnel = ['product_view' => 0, 'cart_add' => 0, 'checkout_started' => 0, 'order_placed' => 0];
        $engagement = ['wishlist_add' => 0, 'wishlist_remove' => 0, 'recommendation_click' => 0];
        $products = [];
        foreach ($events as $row) {
            $data = Arr::row($row);
            $event = Arr::str($data, 'event');
            $key = match ($event) {
                'product.view', 'product_view' => 'product_view',
                'cart.add' => 'cart_add',
                'checkout.start', 'checkout_started' => 'checkout_started',
                'order.placed' => 'order_placed',
                default => null,
            };
            if ($key !== null) $funnel[$key]++;
            if (array_key_exists($event, $engagement)) $engagement[$event]++;
            $properties = json_decode(Arr::str($data, 'payload_json', '{}'), true);
            $properties = is_array($properties) && is_array($properties['properties'] ?? null) ? $properties['properties'] : [];
            $productId = is_string($properties['product_id'] ?? null) ? $properties['product_id'] : '';
            if ($productId !== '') {
                $products[$productId] ??= ['product_id' => $productId, 'views' => 0, 'wishlist_adds' => 0, 'wishlist_removes' => 0];
                if ($event === 'product_view' || $event === 'product.view') $products[$productId]['views']++;
                if ($event === 'wishlist_add') $products[$productId]['wishlist_adds']++;
                if ($event === 'wishlist_remove') $products[$productId]['wishlist_removes']++;
            }
        }

        $lineProducts = [];
        $lineQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->selectRaw('order_lines.sku AS sku, MAX(order_lines.name) AS name, orders.currency AS currency, SUM(order_lines.qty) AS units, SUM(order_lines.qty * order_lines.unit_price_minor) AS revenue_minor')
            ->where('orders.created_at', '>=', $from)->where('orders.created_at', '<=', $to);
        if ($storeId !== null) $lineQuery->where('orders.store_id', $storeId);
        if ($channelId !== null) $lineQuery->where('orders.channel_id', $channelId);
        foreach ($lineQuery->groupBy('order_lines.sku', 'orders.currency')->get() as $row) {
            $data = Arr::row($row);
            $sku = Arr::str($data, 'sku', Arr::str($data, 'name', 'unknown'));
            $lineProducts[$sku] ??= ['sku' => $sku, 'name' => Arr::str($data, 'name', $sku), 'units' => 0, 'revenue_by_currency' => []];
            $lineProducts[$sku]['units'] += Arr::int($data, 'units');
            $currency = strtoupper(Arr::str($data, 'currency', 'UNKNOWN'));
            $lineProducts[$sku]['revenue_by_currency'][$currency] = ($lineProducts[$sku]['revenue_by_currency'][$currency] ?? 0) + Arr::int($data, 'revenue_minor');
        }
        $lineProducts = array_values($lineProducts);
        usort($lineProducts, static fn (array $a, array $b): int => $b['units'] <=> $a['units']);
        usort($products, static fn (array $a, array $b): int => ($b['views'] + $b['wishlist_adds']) <=> ($a['views'] + $a['wishlist_adds']));
        $conversion = static fn (int $part, int $whole): ?float => $whole > 0 ? round($part / $whole, 4) : null;
        ksort($sales); ksort($refunds);
        return [
            'status' => 'observed',
            'authoritative' => ['orders' => $ordersCount, 'sales_minor_by_currency' => $sales, 'refunds_minor_by_currency' => $refunds],
            'funnel_estimate' => ['counts' => $funnel, 'conversion' => ['view_to_cart' => $conversion($funnel['cart_add'], $funnel['product_view']), 'cart_to_checkout' => $conversion($funnel['checkout_started'], $funnel['cart_add']), 'checkout_to_order' => $conversion($funnel['order_placed'], $funnel['checkout_started'])]],
            'product_performance' => ['order_lines' => array_slice($lineProducts, 0, 50), 'engagement_signals' => array_slice($products, 0, 50)],
            'retention_estimate' => ['identified_customers' => $identified, 'repeat_customers' => $repeat, 'repeat_rate' => $identified > 0 ? round($repeat / $identified, 4) : null],
            'engagement_estimate' => $engagement,
            'recommendation_signals' => ['status' => 'unavailable', 'reason' => 'No recommendation impression or click event is persisted.'],
            'privacy' => ['pii' => false, 'guest_orders_excluded_from_retention' => true],
        ];
    }

    /** @return array<int, mixed> */
    private function scopedEvents(string $from, string $to, ?string $storeId, ?string $channelId): array
    {
        $query = AnalyticsEvent::query()->where('created_at', '>=', $from)->where('created_at', '<=', $to);
        if ($storeId !== null) $query->where('store_id', $storeId);
        if ($channelId !== null) $query->where('channel_id', $channelId);
        return $query->limit(10000)->get();
    }

    private function inventoryCount(?string $storeId, ?string $channelId, bool $lowStock = false): int
    {
        $query = $this->inventoryQuery($storeId, $channelId);
        if ($lowStock) $query->whereRaw('(on_hand - reserved) <= low_threshold');
        return (int) $query->cache(30)->count();
    }

    private function inventoryQuery(?string $storeId, ?string $channelId): QueryBuilder
    {
        $query = InventoryItem::query();
        if ($storeId !== null && $channelId !== null) {
            $query->whereRaw('variant_id IN (SELECT pv.id FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE p.store_id = ? AND p.channel_id = ?)', [$storeId, $channelId]);
        }
        return $query;
    }

    /** @return array{0:string,1:string} */
    private function window(?string $from, ?string $to): array
    {
        $endTimestamp = $to !== null ? strtotime($to) : false;
        $startTimestamp = $from !== null ? strtotime($from) : strtotime('-30 days');
        if ($endTimestamp === false) $endTimestamp = time();
        if ($startTimestamp === false) $startTimestamp = strtotime('-30 days');
        $end = date('Y-m-d 23:59:59', $endTimestamp);
        $start = date('Y-m-d 00:00:00', $startTimestamp);
        if ($startTimestamp > $endTimestamp || ($endTimestamp - $startTimestamp) > self::MAX_WINDOW_DAYS * 86400) throw new \InvalidArgumentException('Reporting window must be 1-' . self::MAX_WINDOW_DAYS . ' days');
        return [$start, $end];
    }

    /** @return array<string, int> */
    private function failureCounts(): array
    {
        return ['orders' => Order::query()->where('state', 'failed')->cache(15)->count(), 'payment_operations' => PaymentOperation::query()->whereIn('state', ['failed', 'unknown'])->cache(15)->count(), 'jobs' => CommerceJob::query()->whereIn('state', ['failed', 'dead'])->cache(15)->count()];
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) return '***';
        return substr($parts[0], 0, 1) . '***@' . $parts[1];
    }
}
