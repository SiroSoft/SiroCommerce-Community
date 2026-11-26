<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Shipping provider contract (spec docs/07).
 */
interface ShippingProviderInterface
{
    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function getRates(array $shipment, array $ctx): array;

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function createShipment(array $shipment, array $ctx): array;

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function createReturnLabel(array $shipment, array $ctx): array;

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function normalizeStatus(string $providerStatus, array $ctx = []): array;

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function cancelShipment(string $shipmentId, array $ctx): array;

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function track(string $shipmentId, array $ctx): array;
}
