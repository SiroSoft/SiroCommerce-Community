<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\ReturnLine;
use App\Models\ReturnRequest;
use App\Models\ReturnInventoryPosting;
use App\Models\ReturnEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Modules\Fulfillment\Contracts\ReturnStore;
use App\Support\Arr;
use App\Repositories\Concerns\CreatesModels;

final class ReturnStoreAdapter implements ReturnStore
{
    use CreatesModels;

    public function byIdempotency(string $key): ?array { return $this->row(ReturnRequest::query()->where('idempotency_key', $key)->first()); }
    public function paginateForCustomer(string $customerId, int $page, int $perPage, array $aliasIds = []): array
    {
        $orderIds = [];
        foreach (Order::query()->whereIn('customer_id', array_values(array_unique(array_merge([$customerId], $aliasIds))))->get() as $order) $orderIds[] = Arr::str($order->toArray(), 'id');
        return $this->paginate($orderIds, $page, $perPage);
    }
    public function paginateForOrder(string $orderId, int $page, int $perPage): array { return $this->paginate([$orderId], $page, $perPage); }
    public function findForOrder(string $orderId, string $id): ?array { return $this->row(ReturnRequest::query()->where('id', $id)->where('order_id', $orderId)->first()); }
    public function findForCustomer(string $customerId, string $id, array $aliasIds = []): ?array
    {
        $orders = [];
        foreach (Order::query()->whereIn('customer_id', array_values(array_unique(array_merge([$customerId], $aliasIds))))->get() as $order) $orders[] = Arr::str($order->toArray(), 'id');
        if ($orders === []) return null;
        return $this->row(ReturnRequest::query()->where('id', $id)->whereIn('order_id', $orders)->first());
    }
    /** @return array<string,mixed>|null */ public function byLabelIdempotency(string $key): ?array { return $this->row(ReturnRequest::query()->where('label_idempotency_key', $key)->first()); }
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array { return $this->map(self::createRow(ReturnRequest::class, $data)); }
    public function addLine(array $data): void { self::createRow(ReturnLine::class, $data); }
    public function find(string $id): ?array { return $this->row(ReturnRequest::find($id)); }
    public function paymentForOrder(string $orderId): ?array { return $this->row(Payment::query()->where('order_id', $orderId)->orderBy('id')->first()); }
    public function findForUpdate(string $id): ?array { return $this->row(ReturnRequest::query()->where('id', $id)->lockForUpdate()->first()); }
    public function lines(string $id): array
    {
        $result = [];
        foreach (ReturnLine::query()->where('return_id', $id)->get() as $model) {
            $row = $this->row($model);
            if ($row !== null) $result[] = $row;
        }
        return $result;
    }
    public function returnedQty(string $orderLineId): int
    {
        $total = 0;
        foreach ($this->linesForOrderLine($orderLineId) as $line) {
            $return = ReturnRequest::find(Arr::str($line, 'return_id'));
            $returnRow = $return === null ? null : $this->row($return);
            if ($returnRow !== null && in_array(Arr::str($returnRow, 'state'), ['requested', 'approved', 'inspected', 'approved_refund', 'completed'], true)) $total += Arr::int($line, 'qty');
        }
        return $total;
    }
    public function setState(string $id, string $state, ?string $completedAt = null, array $metadata = []): void
    {
        $row = ReturnRequest::find($id);
        if ($row !== null) {
            $row->setAttribute('state', $state);
            if ($completedAt !== null) $row->setAttribute('completed_at', $completedAt);
            foreach ($metadata as $key => $value) $row->setAttribute($key, $value);
            $row->save();
        }
    }
    public function transition(string $id, array $from, string $state, ?string $completedAt = null, array $metadata = []): bool
    {
        /** @var \Siro\Core\Model|null $row */
        $row = ReturnRequest::query()->where('id', $id)->lockForUpdate()->first();
        if ($row === null || !in_array(Arr::str($row->toArray(), 'state'), $from, true)) return false;
        $row->setAttribute('state', $state);
        if ($completedAt !== null) $row->setAttribute('completed_at', $completedAt);
        foreach ($metadata as $key => $value) $row->setAttribute($key, $value);
        $row->save();
        return true;
    }
    public function inventoryPosted(string $returnId, string $lineId): bool { return ReturnInventoryPosting::query()->where('return_id', $returnId)->where('return_line_id', $lineId)->first() !== null; }
    public function recordInventoryPosting(array $data): void { self::createRow(ReturnInventoryPosting::class, $data); }
    public function recordEvent(array $data): void { self::createRow(ReturnEvent::class, $data); }
    public function setLogistics(string $id, array $metadata): void { $row = ReturnRequest::find($id); if ($row === null) return; foreach ($metadata as $key => $value) $row->setAttribute($key, $value); $row->save(); }
    public function setLineDisposition(string $returnId, string $disposition): void { ReturnLine::query()->where('return_id', $returnId)->update(['disposition' => $disposition]); }
    public function claimLabel(string $returnId, string $idempotencyKey): bool
    {
        /** @var \Siro\Core\Model|null $row */
        $row = ReturnRequest::query()->where('id', $returnId)->lockForUpdate()->first();
        if ($row === null) return false;
        $state = Arr::str($row->toArray(), 'label_state');
        if ($state === 'label_created') return false;
        if ($state === 'processing') {
            $lockedAt = Arr::nullableStr($row->toArray(), 'label_locked_at');
            if ($lockedAt !== null && strtotime($lockedAt) > time() - 300) return false;
        }
        $existingKey = Arr::nullableStr($row->toArray(), 'label_idempotency_key');
        if (is_string($existingKey) && $existingKey !== '' && $existingKey !== $idempotencyKey) return false;
        $row->setAttribute('label_idempotency_key', $idempotencyKey);
        $row->setAttribute('label_state', 'processing');
        $row->setAttribute('label_attempts', Arr::int($row->toArray(), 'label_attempts') + 1);
        $row->setAttribute('label_locked_at', date('Y-m-d H:i:s'));
        $row->setAttribute('label_last_error', null);
        $row->save();
        return true;
    }
    public function queueLabel(string $returnId, string $idempotencyKey): void
    {
        /** @var \Siro\Core\Model|null $row */
        $row = ReturnRequest::query()->where('id', $returnId)->lockForUpdate()->first();
        if ($row === null) throw new \RuntimeException('Return not found');
        $data = $row->toArray();
        $existingKey = Arr::nullableStr($data, 'label_idempotency_key');
        if ($existingKey !== null && $existingKey !== $idempotencyKey) throw new \InvalidArgumentException('Return label uses another key');
        $row->setAttribute('label_idempotency_key', $idempotencyKey);
        $row->setAttribute('label_state', 'queued');
        $row->setAttribute('label_last_error', null);
        $row->save();
    }
    public function completeLabel(string $returnId, array $metadata): void { $this->setLogistics($returnId, $metadata + ['label_state' => 'label_created', 'label_locked_at' => null, 'label_last_error' => null]); }
    public function failLabel(string $returnId, string $error): void { $this->setLogistics($returnId, ['label_state' => 'failed', 'label_locked_at' => null, 'label_last_error' => $error]); }

    /** @param array<string,mixed> $metadata */
    public function updateLine(string $id, array $metadata): void
    {
        $row = ReturnLine::find($id);
        if ($row !== null) { foreach ($metadata as $key => $value) $row->setAttribute($key, $value); $row->save(); }
    }

    /** @return array<int, array<string, mixed>> */
    private function linesForOrderLine(string $orderLineId): array
    {
        $result = [];
        foreach (ReturnLine::query()->where('order_line_id', $orderLineId)->get() as $model) {
            $row = $this->row($model);
            if ($row !== null) $result[] = $row;
        }
        return $result;
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

    /** @param array<int, string> $orderIds
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    private function paginate(array $orderIds, int $page, int $perPage): array
    {
        if ($orderIds === []) return ['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
        $result = ReturnRequest::query()->whereIn('order_id', $orderIds)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage, $page);
        $data = [];
        $models = is_array($result['data'] ?? null) ? $result['data'] : [];
        foreach ($models as $model) {
            $row = $this->row($model);
            if ($row !== null) { $row['lines'] = $this->lines(Arr::requiredStr($row, 'id')); $data[] = $row; }
        }
        /** @var array<string, mixed> $meta */
        $meta = $result['meta'];
        return ['data' => $data, 'meta' => ['page' => Arr::int($meta, 'page', $page), 'per_page' => Arr::int($meta, 'per_page', $perPage), 'total' => Arr::int($meta, 'total'), 'last_page' => Arr::int($meta, 'last_page', 1)]];
    }
}
