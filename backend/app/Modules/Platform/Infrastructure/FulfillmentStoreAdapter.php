<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\FulfillmentLine;
use App\Models\FulfillmentOrder;
use App\Models\Package;
use App\Models\PackageLine;
use App\Models\OrderLine;
use App\Modules\Fulfillment\Contracts\FulfillmentStore;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;

final class FulfillmentStoreAdapter implements FulfillmentStore
{
    use CreatesModels;

    public function byIdempotency(string $key): ?array { return $this->row(FulfillmentOrder::query()->where('idempotency_key', $key)->first()); }
    public function create(array $data): array { $result = self::createRow(FulfillmentOrder::class, $data); return $this->map($result); }
    public function addLine(array $data): void { self::createRow(FulfillmentLine::class, $data); }
    public function find(string $id): ?array { return $this->row(FulfillmentOrder::find($id)); }
    public function lines(string $id): array
    {
        $result = [];
        foreach (FulfillmentLine::query()->where('fulfillment_id', $id)->get() as $model) {
            $row = $this->row($model);
            if ($row !== null) $result[] = $row;
        }
        return $result;
    }
    public function orderLine(string $orderId, string $lineId): ?array { return $this->row(OrderLine::query()->where('id', $lineId)->where('order_id', $orderId)->first()); }
    public function lockOrderLine(string $orderId, string $lineId): void { OrderLine::query()->where('id', $lineId)->where('order_id', $orderId)->lockForUpdate()->first(); }
    public function allocatedQty(string $orderLineId): int
    {
        $total = 0;
        foreach (FulfillmentLine::query()->where('order_line_id', $orderLineId)->get() as $model) {
            $line = $this->row($model);
            $fulfillment = $line === null ? null : FulfillmentOrder::find(Arr::str($line, 'fulfillment_id'));
            $fulfillmentRow = $fulfillment === null ? null : $this->row($fulfillment);
            if ($line !== null && $fulfillmentRow !== null && Arr::str($fulfillmentRow, 'state') !== 'cancelled') $total += Arr::int($line, 'qty');
        }
        return $total;
    }
    public function deliveredQty(string $fulfillmentId, string $orderLineId): int
    {
        $fulfillment = FulfillmentOrder::find($fulfillmentId);
        $fulfillmentRow = $fulfillment === null ? null : $this->row($fulfillment);
        if ($fulfillmentRow === null || Arr::str($fulfillmentRow, 'state') !== 'delivered') return 0;
        foreach (FulfillmentLine::query()->where('fulfillment_id', $fulfillmentId)->where('order_line_id', $orderLineId)->get() as $model) return Arr::int($this->row($model) ?? [], 'qty');
        return 0;
    }
    public function lockForReturn(string $fulfillmentId): void { FulfillmentOrder::query()->where('id', $fulfillmentId)->lockForUpdate()->first(); }
    public function lockForUpdate(string $fulfillmentId): void { FulfillmentOrder::query()->where('id', $fulfillmentId)->lockForUpdate()->first(); }
    public function setState(string $id, string $state): void { $row = FulfillmentOrder::find($id); if ($row !== null) { $row->setAttribute('state', $state); if ($state === 'delivered') $row->setAttribute('delivered_at', date('Y-m-d H:i:s')); $row->save(); } }
    public function markReady(string $id, string $pointCode, string $readyAt, string $readyBy): void { $row = FulfillmentOrder::find($id); if ($row !== null) { $row->setAttribute('state', 'ready_for_pickup'); $row->setAttribute('pickup_point_code', $pointCode); $row->setAttribute('ready_at', $readyAt); $row->setAttribute('ready_by', $readyBy); $row->save(); } }
    public function markCollected(string $id, string $pickedUpAt): void { $row = FulfillmentOrder::find($id); if ($row !== null) { $row->setAttribute('state', 'picked_up'); $row->setAttribute('picked_up_at', $pickedUpAt); $row->save(); } }
    public function packageByIdempotency(string $key): ?array { return $this->row(Package::query()->where('idempotency_key', $key)->first()); }
    public function createPackage(array $data): array { $result = self::createRow(Package::class, $data); return $this->map($result); }
    public function addPackageLine(array $data): void { self::createRow(PackageLine::class, $data); }
    public function setPackageState(string $id, string $state, ?string $tracking = null): void { $row = Package::find($id); if ($row !== null) { $row->setAttribute('state', $state); if ($tracking !== null) $row->setAttribute('tracking', $tracking); $row->save(); } }
    public function package(string $fulfillmentId): ?array { return $this->row(Package::query()->where('fulfillment_id', $fulfillmentId)->first()); }
    public function packages(string $fulfillmentId): array
    {
        $result = [];
        foreach (Package::query()->where('fulfillment_id', $fulfillmentId)->orderBy('id')->get() as $model) {
            $row = $this->row($model); if ($row !== null) $result[] = $row;
        }
        return $result;
    }
    public function setBackorderQty(string $fulfillmentId, int $qty): void
    {
        $row = FulfillmentOrder::find($fulfillmentId);
        if ($row !== null) { $row->setAttribute('backorder_qty', max(0, $qty)); $row->save(); }
    }
    public function packagedQty(string $fulfillmentLineId): int
    {
        $total = 0;
        foreach (PackageLine::query()->where('fulfillment_line_id', $fulfillmentLineId)->get() as $model) $total += Arr::int($this->row($model) ?? [], 'qty');
        return $total;
    }

    /** @return array<string, mixed>|null */
    private function row(mixed $model): ?array
    {
        if (!is_object($model) || !method_exists($model, 'toArray')) return null;
        return $this->map($model->toArray());
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item;
        return $result;
    }
}
