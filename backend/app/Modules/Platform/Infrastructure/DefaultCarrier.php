<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Shipping\Contracts\Carrier;

final class DefaultCarrier implements Carrier
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function rates(array $context): array { return []; }

    public function create(array $shipment, string $idempotencyKey): array
    {
        return ['carrier' => 'default', 'provider_ref' => 'shipment-' . substr(hash('sha256', $idempotencyKey), 0, 20), 'state' => 'created', 'tracking' => 'TRK-' . substr(hash('sha256', $idempotencyKey), 0, 12)];
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function createReturnLabel(array $shipment, string $idempotencyKey): array
    {
        return ['carrier' => 'default', 'provider_ref' => 'return-' . substr(hash('sha256', $idempotencyKey), 0, 20), 'state' => 'created', 'tracking' => 'TRK-' . substr(hash('sha256', $idempotencyKey), 0, 12)];
    }

    public function cancel(string $providerShipmentId, string $idempotencyKey): array { return ['provider_ref' => $providerShipmentId, 'state' => 'cancelled']; }
    public function track(string $providerShipmentId): array { return ['provider_ref' => $providerShipmentId, 'state' => 'in_transit']; }
}
