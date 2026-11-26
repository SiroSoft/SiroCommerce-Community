<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

interface Carrier
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function rates(array $context): array;

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function create(array $shipment, string $idempotencyKey): array;

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    public function createReturnLabel(array $shipment, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function cancel(string $providerShipmentId, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function track(string $providerShipmentId): array;
}
