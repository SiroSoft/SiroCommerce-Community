<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Shipping\Contracts\Carrier;
use App\Modules\Shipping\Contracts\DhlTransport;
use App\Modules\Shipping\Contracts\RateProvider;
use App\Support\ProviderEvidence;

final class DhlCarrier implements Carrier, RateProvider
{
    public function __construct(private readonly DhlTransport $transport) {}

    public function quote(array $context): array
    {
        $response = $this->transport->request('rate', $context);
        $rates = $response['rates'] ?? [];
        if (!is_array($rates)) return [];
        $normalized = [];
        foreach ($rates as $rate) {
            if (!is_array($rate)) continue;
            /** @var array<string, mixed> $rate */
            $safe = $this->redact($rate);
            if (!is_string($safe['code'] ?? null) || !is_numeric($safe['amount_minor'] ?? null) || !is_string($safe['currency'] ?? null)) continue;
            $safe['amount_minor'] = (int) $safe['amount_minor'];
            $safe['currency'] = strtoupper($safe['currency']);
            $safe['provider'] = 'dhl';
            $normalized[] = $safe;
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function rates(array $context): array { return $this->quote($context); }

    public function create(array $shipment, string $idempotencyKey): array
    {
        return $this->redact($this->transport->request('create_shipment', ['shipment' => $shipment, 'idempotency_key' => $idempotencyKey]));
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function createReturnLabel(array $shipment, string $idempotencyKey): array
    {
        return $this->redact($this->transport->request('create_return_label', ['shipment' => $shipment, 'idempotency_key' => $idempotencyKey]));
    }

    public function cancel(string $providerShipmentId, string $idempotencyKey): array
    {
        return $this->redact($this->transport->request('cancel_shipment', ['provider_shipment_id' => $providerShipmentId, 'idempotency_key' => $idempotencyKey]));
    }

    public function track(string $providerShipmentId): array
    {
        return $this->redact($this->transport->request('track', ['provider_shipment_id' => $providerShipmentId]));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        $safe = ProviderEvidence::redact($data);
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($safe as $key => $value) if (is_string($key)) $result[$key] = $value;
        return $result;
    }
}
