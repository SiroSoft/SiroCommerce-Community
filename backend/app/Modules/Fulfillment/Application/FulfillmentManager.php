<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Application;

use App\Modules\Fulfillment\Contracts\FulfillmentService;
use App\Modules\Fulfillment\Contracts\FulfillmentStore;
use App\Modules\Shipping\Contracts\Carrier;
use App\Modules\Inventory\Contracts\InventoryStore;
use App\Support\Arr;
use App\Support\Ids;
use App\Support\DatabaseRetry;
use Siro\Core\Database;
use Siro\Core\Event;

final class FulfillmentManager implements FulfillmentService
{
    public function __construct(
        private readonly FulfillmentStore $store,
        private readonly Carrier $carrier,
        private readonly ?InventoryStore $inventory = null,
    ) {
    }

    /** @param array<int, array<string, mixed>> $lines @return array<string, mixed> */
    public function allocate(string $orderId, array $lines, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $existing = $this->store->byIdempotency($idempotencyKey);
        if ($existing !== null) return $existing;
        if ($lines === []) throw new \InvalidArgumentException('Fulfillment lines are required');
        try {
            $result = DatabaseRetry::transaction(function () use ($orderId, $lines, $idempotencyKey): array {
            $existing = $this->store->byIdempotency($idempotencyKey);
            if ($existing !== null) return $existing;
            $fulfillment = $this->store->create([
                'id' => Ids::new(),
                'order_id' => $orderId,
                'state' => 'allocated',
                 'idempotency_key' => $idempotencyKey,
                 'created_at' => date('Y-m-d H:i:s'),
                 'allocation_evidence_json' => json_encode(['source_selection' => array_map(static fn (array $line): array => ['order_line_id' => $line['order_line_id'] ?? null, 'source_id' => $line['source_id'] ?? 'default', 'substitution_variant_id' => $line['substitution_variant_id'] ?? null], $lines)], JSON_THROW_ON_ERROR),
             ]);
            $requested = [];
            foreach ($lines as $rawLine) {
                $line = $this->map($rawLine);
                $lineId = Arr::requiredStr($line, 'order_line_id');
                $qty = Arr::requiredInt($line, 'qty');
                if ($qty <= 0) throw new \InvalidArgumentException('Fulfillment quantity must be positive');
                $requested[] = ['line_id' => $lineId, 'qty' => $qty, 'source_id' => Arr::str($line, 'source_id', 'default'), 'substitution_variant_id' => Arr::nullableStr($line, 'substitution_variant_id'), 'allow_backorder' => ($line['allow_backorder'] ?? true) !== false, 'explicit_source' => array_key_exists('source_id', $line)];
            }
            $backorder = 0;
            foreach ($requested as $request) {
                $lineId = Arr::requiredStr($request, 'line_id');
                $qty = Arr::requiredInt($request, 'qty');
                $orderLine = $this->store->orderLine($orderId, $lineId);
                if ($orderLine === null) throw new \InvalidArgumentException('Order line does not belong to order');
                $this->store->lockOrderLine($orderId, $lineId);
                if ($qty + $this->store->allocatedQty($lineId) > Arr::requiredInt($orderLine, 'qty')) throw new \InvalidArgumentException('Fulfillment quantity exceeds ordered quantity');
                $source = Arr::requiredStr($request, 'source_id');
                $reservationId = null;
                $actual = $qty;
                if ($this->inventory !== null && (bool) $request['explicit_source']) {
                    $variant = Arr::requiredStr($orderLine, 'variant_id');
                    $item = $this->inventory->item($variant, $source);
                    $available = $item === null ? 0 : max(0, Arr::int($item, 'on_hand') - Arr::int($item, 'reserved'));
                    $actual = min($qty, $available);
                    if ($actual > 0) {
                        if ($this->inventory->tryReserve($variant, $actual, $source) !== 1) throw new \RuntimeException('Inventory allocation changed concurrently');
                        $reservation = $this->inventory->createReservation(['id' => Ids::new(), 'variant_id' => $variant, 'source_id' => $source, 'qty' => $actual, 'state' => 'held', 'order_id' => $orderId, 'allocation_key' => $idempotencyKey, 'idempotency_key' => $idempotencyKey . ':' . $lineId . ':' . $source]);
                        $reservationId = Arr::nullableStr($reservation, 'id');
                    }
                    if ($actual < $qty && !(bool) $request['allow_backorder']) throw new \InvalidArgumentException('Insufficient inventory for requested source');
                    $backorder += $qty - $actual;
                }
                if ($actual > 0) $this->store->addLine(['id' => Ids::new(), 'fulfillment_id' => Arr::requiredStr($fulfillment, 'id'), 'order_line_id' => $lineId, 'qty' => $actual, 'source_id' => $source, 'reservation_id' => $reservationId, 'substitution_variant_id' => $request['substitution_variant_id']]);
            }
            $this->store->setBackorderQty(Arr::requiredStr($fulfillment, 'id'), $backorder);
            $updated = $this->store->find(Arr::requiredStr($fulfillment, 'id'));
            return $updated ?? $fulfillment;
            });
        } catch (\Throwable $e) {
            $existing = $this->store->byIdempotency($idempotencyKey);
            if ($existing !== null) {
                $result = $existing;
            } else {
                throw $e;
            }
        }
        if (!is_array($result)) throw new \RuntimeException('Fulfillment allocation returned an invalid result');
        $result = $this->map($result);
        Event::emit('fulfillment.allocated', ['fulfillment_id' => Arr::requiredStr($result, 'id'), 'order_id' => $orderId]);
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>>|null $requestedLines
     * @return array<string, mixed>
     */
    public function ship(string $fulfillmentId, string $idempotencyKey, ?array $requestedLines = null): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        try {
            $created = DatabaseRetry::transaction(function () use ($fulfillmentId, $idempotencyKey, $requestedLines): array {
                $existing = $this->store->packageByIdempotency($idempotencyKey);
                if ($existing !== null) return $existing;
                // Serialize package ownership for this fulfillment before calling
                // the provider; the provider also receives the same idempotency key.
                $this->store->lockForUpdate($fulfillmentId);
                $existing = $this->store->packageByIdempotency($idempotencyKey);
                if ($existing !== null) return $existing;
                $fulfillment = $this->store->find($fulfillmentId);
                if ($fulfillment === null) throw new \RuntimeException('Fulfillment not found');
                 if (!in_array(Arr::str($fulfillment, 'state'), ['allocated', 'label_created', 'shipped'], true)) throw new \InvalidArgumentException('Fulfillment is not ready to ship');
                 $lines = $this->store->lines($fulfillmentId);
                 if ($requestedLines !== null) {
                     $requested = [];
                     foreach ($requestedLines as $rawLine) {
                         $line = $this->map($rawLine);
                         $lineId = Arr::requiredStr($line, 'fulfillment_line_id');
                         $qty = Arr::requiredInt($line, 'qty');
                         if ($qty <= 0) throw new \InvalidArgumentException('Package quantity must be positive');
                         $requested[$lineId] = ($requested[$lineId] ?? 0) + $qty;
                     }
                     $lines = array_values(array_filter(array_map(function (array $line) use ($requested): ?array {
                         $lineId = Arr::requiredStr($line, 'id');
                         $qty = $requested[$lineId] ?? 0;
                         if ($qty > Arr::requiredInt($line, 'qty') - $this->store->packagedQty($lineId)) throw new \InvalidArgumentException('Package quantity exceeds unshipped quantity');
                         if ($qty === 0) return null;
                         $line['qty'] = $qty;
                         return $line;
                     }, $lines)));
                     if ($lines === []) throw new \InvalidArgumentException('Package lines are required');
                 }
                 $package = $this->carrier->create(['fulfillment_id' => $fulfillmentId, 'lines' => $lines], $idempotencyKey);
                $created = $this->store->createPackage(['id' => Ids::new(), 'fulfillment_id' => $fulfillmentId, 'carrier' => Arr::str($package, 'carrier', 'default'), 'provider_ref' => Arr::nullableStr($package, 'provider_ref'), 'state' => 'shipped', 'idempotency_key' => $idempotencyKey, 'tracking' => Arr::nullableStr($package, 'tracking')]);
                 foreach ($lines as $line) $this->store->addPackageLine(['id' => Ids::new(), 'package_id' => Arr::requiredStr($created, 'id'), 'fulfillment_line_id' => Arr::requiredStr($line, 'id'), 'qty' => Arr::requiredInt($line, 'qty')]);
                $this->store->setState($fulfillmentId, 'shipped');
                return $created;
            });
        } catch (\Throwable $e) {
            $existing = $this->store->packageByIdempotency($idempotencyKey);
            if ($existing !== null) return $existing;
            throw $e;
        }
        $created = $this->map($created);
        Event::emit('fulfillment.shipped', ['fulfillment_id' => $fulfillmentId, 'package_id' => Arr::requiredStr($created, 'id')]);
        return $created;
    }

    /** @return array<string, mixed> */
    public function deliver(string $fulfillmentId, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $fulfillment = $this->store->find($fulfillmentId);
        if ($fulfillment === null) throw new \RuntimeException('Fulfillment not found');
        if (Arr::str($fulfillment, 'state') === 'delivered') return $fulfillment;
        if (Arr::str($fulfillment, 'state') !== 'shipped') throw new \InvalidArgumentException('Fulfillment is not shipped');
        foreach ($this->store->lines($fulfillmentId) as $line) if ($this->store->packagedQty(Arr::requiredStr($line, 'id')) < Arr::requiredInt($line, 'qty')) throw new \InvalidArgumentException('All fulfillment lines must be shipped before delivery');
        $this->store->setState($fulfillmentId, 'delivered');
        Event::emit('fulfillment.delivered', ['fulfillment_id' => $fulfillmentId, 'idempotency_key' => $idempotencyKey]);
        $updated = $this->store->find($fulfillmentId);
        if ($updated === null) throw new \RuntimeException('Fulfillment lost after delivery');
        return $updated;
    }

    /** @return array<string, mixed> */
    public function markReady(string $fulfillmentId, string $idempotencyKey, ?string $pointCode = null): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $fulfillment = $this->store->find($fulfillmentId);
        if ($fulfillment === null) throw new \RuntimeException('Fulfillment not found');
        $state = Arr::str($fulfillment, 'state');
        if ($state === 'ready_for_pickup') return $fulfillment;
        if ($state !== 'allocated') throw new \InvalidArgumentException('Fulfillment is not ready to stage for pickup');
        $code = $pointCode !== null && trim($pointCode) !== '' ? strtoupper(trim($pointCode)) : Arr::str($fulfillment, 'pickup_point_code');
        if ($code === '') {
            $order = \App\Models\Order::find(Arr::str($fulfillment, 'order_id'));
            $code = $order === null ? '' : Arr::str(Arr::row($order), 'pickup_point_code');
        }
        if ($code === '') throw new \InvalidArgumentException('Pickup point is required');
        $points = new PickupPointService();
        $point = $points->activePoint($code);
        if ($point === null) throw new \InvalidArgumentException('Pickup point is unknown or disabled');
        $now = date('Y-m-d H:i:s');
        $this->store->markReady($fulfillmentId, $code, $now, $points->readyBy($point));
        Event::emit('fulfillment.ready_for_pickup', ['fulfillment_id' => $fulfillmentId, 'order_id' => Arr::str($fulfillment, 'order_id'), 'pickup_point_code' => $code, 'idempotency_key' => $idempotencyKey]);
        $updated = $this->store->find($fulfillmentId);
        if ($updated === null) throw new \RuntimeException('Fulfillment lost after staging');
        return $updated;
    }

    /** @return array<string, mixed> */
    public function collect(string $fulfillmentId, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $fulfillment = $this->store->find($fulfillmentId);
        if ($fulfillment === null) throw new \RuntimeException('Fulfillment not found');
        $state = Arr::str($fulfillment, 'state');
        if ($state === 'picked_up') return $fulfillment;
        if ($state !== 'ready_for_pickup') throw new \InvalidArgumentException('Fulfillment is not staged for pickup');
        $this->store->markCollected($fulfillmentId, date('Y-m-d H:i:s'));
        Event::emit('fulfillment.picked_up', ['fulfillment_id' => $fulfillmentId, 'order_id' => Arr::str($fulfillment, 'order_id'), 'idempotency_key' => $idempotencyKey]);
        $updated = $this->store->find($fulfillmentId);
        if ($updated === null) throw new \RuntimeException('Fulfillment lost after handover');
        return $updated;
    }

    /** @return array<string, mixed> */
    public function cancel(string $fulfillmentId, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $fulfillment = $this->store->find($fulfillmentId);
        if ($fulfillment === null) throw new \RuntimeException('Fulfillment not found');
        $state = Arr::str($fulfillment, 'state');
        if ($state === 'cancelled') return $fulfillment;
        if ($state === 'delivered') throw new \InvalidArgumentException('Delivered fulfillment cannot be cancelled');
        if ($state === 'picked_up') throw new \InvalidArgumentException('Collected pickup cannot be cancelled');
        foreach ($this->store->packages($fulfillmentId) as $package) {
          if (Arr::str($package, 'state') !== 'cancelled') {
            $providerRef = Arr::nullableStr($package, 'provider_ref');
            if ($providerRef !== null) $this->carrier->cancel($providerRef, $idempotencyKey);
            $this->store->setPackageState(Arr::requiredStr($package, 'id'), 'cancelled');
          }
        }
        if ($this->inventory !== null) foreach ($this->store->lines($fulfillmentId) as $line) {
            $reservation = Arr::nullableStr($line, 'reservation_id');
            $unshipped = Arr::requiredInt($line, 'qty') - $this->store->packagedQty(Arr::requiredStr($line, 'id'));
            if ($reservation !== null && $unshipped > 0) {
                $orderLine = $this->store->orderLine(Arr::requiredStr($fulfillment, 'order_id'), Arr::requiredStr($line, 'order_line_id'));
                if ($orderLine === null) throw new \RuntimeException('Fulfillment order line evidence is missing');
                $this->inventory->releaseReserved(Arr::requiredStr($orderLine, 'variant_id'), $unshipped, Arr::str($line, 'source_id', 'default'));
                $this->inventory->markReservation($reservation, 'released');
            }
        }
        $this->store->setState($fulfillmentId, 'cancelled');
        $updated = $this->store->find($fulfillmentId);
        if ($updated === null) throw new \RuntimeException('Fulfillment lost after cancellation');
        Event::emit('fulfillment.cancelled', ['fulfillment_id' => $fulfillmentId, 'idempotency_key' => $idempotencyKey]);
        return $updated;
    }

    /** @return array<string, mixed> */
    public function track(string $fulfillmentId): array
    {
        $fulfillment = $this->store->find($fulfillmentId);
        if ($fulfillment === null) throw new \RuntimeException('Fulfillment not found');
        $package = $this->store->package($fulfillmentId);
        if ($package === null) throw new \RuntimeException('Shipment package not found');
        $providerRef = Arr::nullableStr($package, 'provider_ref');
        if ($providerRef === null) throw new \RuntimeException('Shipment has no provider reference');
        $result = $this->carrier->track($providerRef);
        $state = Arr::str($result, 'state', Arr::str($package, 'state'));
        $tracking = Arr::nullableStr($result, 'tracking');
        $this->store->setPackageState(Arr::requiredStr($package, 'id'), $state, $tracking);
        $updated = $this->store->package($fulfillmentId);
        if ($updated === null) throw new \RuntimeException('Shipment package lost after tracking');
        Event::emit('fulfillment.tracked', ['fulfillment_id' => $fulfillmentId, 'package_id' => Arr::requiredStr($package, 'id'), 'state' => $state]);
        return $updated;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (!is_array($value)) throw new \InvalidArgumentException('Fulfillment data must be an object');
        $result = [];
        foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item;
        return $result;
    }
}
