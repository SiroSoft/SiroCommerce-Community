<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Models\AnalyticsEvent;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\StockLedger;
use App\Modules\Dev\Contracts\DevOperations;
use App\Modules\Inventory\Application\InventoryManager;
use App\Modules\Order\Contracts\OrderReader;
use App\Modules\Seo\Application\SeoService;
use App\Modules\Smart\Application\RescueService;
use App\Support\Arr;
final class DevRuntimeAdapter implements DevOperations
{
    public function __construct(private readonly OrderReader $orders, private readonly InventoryManager $inventory, private readonly RescueService $rescue, private readonly SeoService $seo) {}
    /**
     * @param class-string<\Siro\Core\Model> $model
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $model, string $column, string $value): array { $out = []; foreach ($model::query()->where($column, $value)->get() as $item) $out[] = Arr::row($item); return $out; }
    /** @return array<string, mixed> */ public function trace(string $id): array { $order = $this->orders->find($id); if ($order === null) throw new \RuntimeException('Order not found'); $timeline = [['at' => Arr::dt($order, 'created_at'), 'step' => 'order.placed', 'detail' => 'state=' . Arr::str($order, 'state')]]; foreach ($this->orders->transitions($id) as $transition) $timeline[] = ['at' => Arr::dt($transition, 'created_at'), 'step' => 'order.transition', 'detail' => Arr::str($transition, 'from_state') . ' -> ' . Arr::str($transition, 'to_state') . ' by ' . Arr::str($transition, 'actor')]; foreach ([Payment::class => 'payment', Shipment::class => 'shipment', Refund::class => 'refund'] as $model => $step) foreach ($this->rows($model, 'order_id', $id) as $row) $timeline[] = ['at' => '', 'step' => $step, 'detail' => Arr::str($row, 'provider') . '/' . Arr::str($row, 'state')]; foreach ($this->rows(Reservation::class, 'order_id', $id) as $reservation) { $timeline[] = ['at' => '', 'step' => 'inventory', 'detail' => 'reservation ' . Arr::str($reservation, 'state') . ' qty=' . Arr::str($reservation, 'qty')]; foreach ($this->rows(StockLedger::class, 'ref_id', Arr::str($reservation, 'id')) as $ledger) $timeline[] = ['at' => Arr::dt($ledger, 'created_at'), 'step' => 'ledger', 'detail' => Arr::str($ledger, 'reason') . ' delta=' . Arr::str($ledger, 'delta')]; } foreach ($this->rows(AnalyticsEvent::class, 'entity_id', $id) as $event) $timeline[] = ['at' => Arr::dt($event, 'created_at'), 'step' => 'analytics', 'detail' => Arr::str($event, 'event')]; usort($timeline, static fn (array $a, array $b): int => strcmp(Arr::str($a, 'at'), Arr::str($b, 'at'))); return ['order' => $order, 'lines' => $this->orders->lines($id), 'timeline' => $timeline, 'counts' => ['transitions' => count($this->orders->transitions($id)), 'payments' => count($this->rows(Payment::class, 'order_id', $id))]]; }
    /** @return array<int, array<string, mixed>> */ public function diagnose(string $scope): array { $findings = []; if ($scope === 'all' || $scope === 'inventory') foreach (InventoryItem::query()->get() as $model) { $item = Arr::row($model); $id = Arr::str($item, 'variant_id'); $available = Arr::int($item, 'on_hand') - Arr::int($item, 'reserved'); if ($available < 0) $findings[] = ['scope' => 'inventory', 'level' => 'critical', 'finding' => "Negative availability for variant {$id} ({$available})", 'evidence' => "GET /api/admin/inventory/{$id}", 'fix' => "PUT /api/admin/inventory/{$id} to correct on_hand"]; if (ProductVariant::query()->where('id', $id)->first() === null) $findings[] = ['scope' => 'inventory', 'level' => 'warning', 'finding' => "Orphan inventory item for missing variant {$id}", 'evidence' => "GET /api/admin/inventory/{$id}", 'fix' => 'Delete item or recreate variant']; } if ($scope === 'all' || $scope === 'seo') foreach ($this->seo->health() as $issue) $findings[] = ['scope' => 'seo', 'level' => 'warning', 'finding' => $issue['type'] . ' at ' . $issue['entity'], 'evidence' => 'GET /api/admin/seo/health', 'fix' => $issue['fix']]; if ($scope === 'all' || $scope === 'inventory') $findings = array_merge($findings, $this->expiredFindings()); return $findings; }
    /** @return array<int, array<string, mixed>> */ private function expiredFindings(): array { $findings = []; foreach (Reservation::query()->where('state', 'held')->get() as $model) { $row = Arr::row($model); if (Arr::dt($row, 'expires_at') !== '' && Arr::dt($row, 'expires_at') < date('Y-m-d H:i:s')) $findings[] = ['scope' => 'inventory', 'level' => 'warning', 'finding' => 'Expired held reservation ' . Arr::str($row, 'id'), 'evidence' => 'GET /api/admin/smart/inventory', 'fix' => 'POST /api/admin/dev/recover {"action":"release-expired"}']; } return $findings; }
    /** @return array<string, mixed> */ public function releaseExpired(bool $dry, string $actor): array { $expired = []; foreach (Reservation::query()->where('state', 'held')->get() as $model) { $row = Arr::row($model); if (Arr::dt($row, 'expires_at') !== '' && Arr::dt($row, 'expires_at') < date('Y-m-d H:i:s')) $expired[] = Arr::requiredStr($row, 'id'); } if (!$dry) foreach ($expired as $id) $this->inventory->release($id, "recovery:expired by {$actor}"); return ['action' => 'release-expired', 'dry_run' => $dry, 'released' => $expired, 'verified' => true]; }
    /** @return array<string, mixed> */ public function rescue(string $id, string $actor, bool $dry): array { if ($dry) return ['order_id' => $id, 'dry_run' => true, 'would' => 'rebuild cart from order snapshot lines without mutating the order', 'audit' => "dry-run by {$actor}: no changes made"]; $report = $this->rescue->rescue($id, $actor); $report['dry_run'] = false; return $report; }
}
