<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Shipping\Contracts\DhlTransport;

final class DhlSandboxTransport implements DhlTransport
{
    /** @return array<string, mixed> */
    public function request(string $operation, array $payload): array
    {
        return match ($operation) {
            'rate' => ['rates' => [['code' => 'dhl-express-worldwide', 'name' => 'DHL Express Worldwide', 'amount_minor' => 250000, 'currency' => 'VND', 'provider' => 'dhl']]],
            'create_shipment' => ['provider_reference' => 'DHL-SANDBOX-' . substr(hash('sha256', is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : ''), 0, 12), 'tracking_number' => 'JD0000000000', 'label_url' => null],
            'track' => ['provider_reference' => $payload['provider_shipment_id'] ?? null, 'tracking_number' => $payload['provider_shipment_id'] ?? null, 'status' => 'in_transit'],
            'cancel_shipment' => ['cancelled' => true, 'provider_reference' => $payload['provider_shipment_id'] ?? null],
            'create_return_label' => ['provider_reference' => 'DHL-RETURN-' . substr(hash('sha256', is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : ''), 0, 12), 'tracking_number' => 'JDRETURN0000', 'label_url' => null],
            default => throw new \InvalidArgumentException('Unsupported DHL operation'),
        };
    }
}
