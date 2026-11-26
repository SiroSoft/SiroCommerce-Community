<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\ShippingShipment;
use App\Models\ShippingTrackingEvent;
use App\Modules\Shipping\Contracts\ShippingShipmentStore;
use App\Support\Arr;
use App\Support\Ids;

final class ShippingShipmentStoreAdapter implements ShippingShipmentStore
{
    public function byIdempotency(string $provider, string $key): ?array
    {
        $row = ShippingShipment::query()->where('provider', $provider)->where('idempotency_key', $key)->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array
    {
        return ShippingShipment::create(['id' => Ids::new()] + $data)->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        ShippingShipment::query()->where('id', $id)->update($data);
        $row = ShippingShipment::find($id);
        if ($row === null) throw new \RuntimeException('Shipment not found after update');
        return $row->toArray();
    }

    public function byTracking(string $provider, string $tracking): ?array
    {
        $row = ShippingShipment::query()->where('provider', $provider)->where('tracking_number', $tracking)->first();
        return $row === null ? null : Arr::row($row);
    }

    public function setState(string $id, string $state): void
    {
        $row = ShippingShipment::find($id);
        if ($row === null) throw new \RuntimeException('Shipment not found');
        $rank = ['pending' => 0, 'retryable' => 0, 'label_created' => 1, 'picked_up' => 2, 'in_transit' => 3, 'out_for_delivery' => 4, 'delivered' => 5, 'returned' => 6, 'cancelled' => 6, 'exception' => 1, 'unknown' => 0];
        if (($rank[$state] ?? 0) < ($rank[(string) $row->getAttribute('state')] ?? 0)) return;
        ShippingShipment::query()->where('id', $id)->update(['state' => $state]);
    }

    public function addTrackingEvent(array $data): void
    {
        ShippingTrackingEvent::create(['id' => Ids::new()] + $data);
    }
}
