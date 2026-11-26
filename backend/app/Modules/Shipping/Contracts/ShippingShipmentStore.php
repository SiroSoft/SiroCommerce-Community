<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

interface ShippingShipmentStore
{
    /** @return array<string, mixed>|null */
    public function byIdempotency(string $provider, string $key): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /** @return array<string, mixed>|null */
    public function byTracking(string $provider, string $tracking): ?array;

    public function setState(string $id, string $state): void;

    /** @param array<string, mixed> $data */
    public function addTrackingEvent(array $data): void;
}
