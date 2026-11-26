<?php
declare(strict_types=1);
namespace App\Modules\Smart\Application;
use App\Modules\Analytics\Application\AnalyticsService;
use App\Modules\Inventory\Application\InventoryAdminService;
use App\Modules\Order\Application\OrderManager;
use App\Modules\Seo\Application\SeoService;
use App\Modules\Smart\Contracts\SmartOperations;
use App\Modules\Smart\Contracts\SmartReadStore;
use App\Support\Arr;
final class SmartService implements SmartOperations
{
    public function __construct(private readonly SmartReadStore $read, private readonly RescueService $rescueService, private readonly InventoryAdminService $inventoryAdmin, private readonly OrderManager $orders, private readonly SeoService $seo, private readonly AnalyticsService $analytics, private readonly ?SearchService $searchService = null) {}
    /** @return array<string, mixed> */
    public function actionCenter(): array
    {
        $urgent = []; $important = []; $growth = [];
        foreach ($this->read->failedOrders() as $order) { $id = Arr::str($order, 'id'); $urgent[] = ['title' => 'Failed order ' . Arr::str($order, 'number') . ' needs rescue', 'action' => "POST /api/admin/smart/rescue/{$id}", 'evidence' => "GET /api/admin/orders/{$id}"]; }
        foreach ($this->inventory() as $item) { $variantId = Arr::str($item, 'variant_id'); $sku = Arr::str($item, 'sku'); if (Arr::str($item, 'risk') === 'stockout') $urgent[] = ['title' => 'Stockout risk: ' . $sku, 'action' => "PUT /api/admin/inventory/{$variantId}", 'evidence' => "GET /api/admin/inventory/{$variantId}"]; elseif (Arr::str($item, 'risk') === 'low') $important[] = ['title' => 'Low stock: ' . $sku, 'action' => "PUT /api/admin/inventory/{$variantId}", 'evidence' => "GET /api/admin/inventory/{$variantId}"]; }
        $awaiting = count($this->read->awaitingPaymentOrders()); if ($awaiting > 0) $important[] = ['title' => $awaiting . ' orders awaiting payment', 'action' => 'GET /api/admin/orders', 'evidence' => 'GET /api/admin/analytics/funnel'];
        foreach ($this->seo->health() as $issue) $growth[] = ['title' => 'SEO: ' . $issue['type'] . ' (' . $issue['entity'] . ')', 'action' => $issue['fix'], 'evidence' => 'GET /api/admin/seo/health'];
        foreach ($this->analytics->funnel()->anomalies as $anomaly) $growth[] = ['title' => 'Conversion: ' . $anomaly, 'action' => 'GET /api/admin/analytics/funnel', 'evidence' => 'GET /api/admin/analytics/funnel'];
        return ['urgent' => $urgent, 'important' => $important, 'growth' => $growth];
    }
    /** @return array<string, mixed> */ public function rescue(string $id, string $actor): array { return $this->rescueService->rescue($id, $actor); }
    /** @return array<string, mixed> */ public function search(string $query): array { $result = $this->searchService?->query($query) ?? $this->read->search($query); $result['commands'] = $this->commands($query); return $result; }
    /** @return array{ok: bool, message: string} */
    public function execute(string $command, string $actor): array
    {
        $parts = preg_split('/\s+/', trim($command)); if ($parts === false || $parts === []) return ['ok' => false, 'message' => 'Empty command']; $verb = strtolower($parts[0]);
        if ($verb === 'stock' && count($parts) === 3 && is_numeric($parts[2])) { $this->inventoryAdmin->setOnHand($parts[1], (int) $parts[2], $actor); return ['ok' => true, 'message' => "Stock set for {$parts[1]}"]; }
        if ($verb === 'ship' && count($parts) === 2) { $order = $this->read->orderByNumberOrId($parts[1]); if ($order === null) return ['ok' => false, 'message' => 'Order not found']; $path = ['awaiting_payment' => ['paid', 'processing', 'ready_to_ship', 'shipped'], 'paid' => ['processing', 'ready_to_ship', 'shipped'], 'processing' => ['ready_to_ship', 'shipped'], 'ready_to_ship' => ['shipped']]; $state = Arr::str($order, 'state'); if (!isset($path[$state])) return ['ok' => false, 'message' => "Cannot ship from {$state}"]; foreach ($path[$state] as $to) $this->orders->transition(Arr::requiredStr($order, 'id'), $to, $actor, 'smart-command'); return ['ok' => true, 'message' => 'Order shipped via ' . implode(' -> ', $path[$state])]; }
        return ['ok' => false, 'message' => 'Unknown command'];
    }
    /** @return array<int, array<string, mixed>> */
    public function inventory(): array
    {
        $out = []; $since = date('Y-m-d H:i:s', time() - 14 * 86400);
        foreach ($this->read->inventoryItems() as $item) { $id = Arr::str($item, 'variant_id'); $sold = 0; foreach ($this->read->recentLedgers($id, $since) as $ledger) if (Arr::str($ledger, 'reason') === 'commit' && Arr::dt($ledger, 'created_at') >= $since) $sold += abs(Arr::int($ledger, 'delta')); if ($sold === 0) foreach ($this->read->committedReservations($id) as $reservation) $sold += Arr::int($reservation, 'qty'); $velocity = $sold / 14; $available = Arr::int($item, 'on_hand') - Arr::int($item, 'reserved'); $cover = $velocity > 0 ? $available / $velocity : 999.0; $variant = $this->read->variant($id); $sku = $variant === null ? $id : Arr::str($variant, 'sku', $id); $risk = $available <= 0 ? 'stockout' : (($cover < 7 || $available <= Arr::int($item, 'low_threshold')) ? ($cover < 7 ? 'stockout' : 'low') : 'ok'); $out[] = ['variant_id' => $id, 'sku' => $sku, 'available' => $available, 'daily_velocity' => round($velocity, 2), 'days_cover' => round($cover, 1), 'risk' => $risk]; }
        return $out;
    }
    /** @return array<int, array<string, mixed>> */ public function setup(): array { $out = []; foreach ($this->read->products() as $product) { $id = Arr::str($product, 'id'); $missing = []; if ($this->read->variantsForProduct($id) === []) $missing[] = 'variants'; if ($this->seo->meta('product', $id) === null) $missing[] = 'seo'; if ($missing !== []) $out[] = ['product_id' => $id, 'name' => Arr::str($product, 'name'), 'missing' => $missing, 'next' => "PUT /api/admin/products/{$id}"]; } return $out; }
    /** @return array<int, array<string, string>> */ public function conversion(): array { $out = []; foreach ($this->analytics->funnel()->anomalies as $anomaly) $out[] = ['signal' => $anomaly, 'evidence' => 'GET /api/admin/analytics/funnel']; return $out; }
    /** @return array<int, array{command: string, hint: string}> */ private function commands(string $query): array { $out = []; if (preg_match('/^refund\s+(\S+)\s+(\d+)$/i', $query, $m) === 1) $out[] = ['command' => "refund {$m[1]} {$m[2]}", 'hint' => 'Refund order by number + amount minor']; if (preg_match('/^stock\s+(\S+)\s+(\d+)$/i', $query, $m) === 1) $out[] = ['command' => "stock {$m[1]} {$m[2]}", 'hint' => 'Set on-hand by variant id + qty']; if (preg_match('/^ship\s+(\S+)$/i', $query, $m) === 1) $out[] = ['command' => "ship {$m[1]}", 'hint' => 'Advance order toward shipped']; return $out; }
}
