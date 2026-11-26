<?php

declare(strict_types=1);

namespace App\Extensions;

use App\Contracts\ExtensionInterface;
use App\Support\Arr;

/**
 * Example extension: flat-rate shipping (free over threshold).
 * Built third-party-style: no core modification, version-pinned.
 */
final class FlatRateShipping implements \App\Contracts\ShippingProviderInterface, ExtensionInterface
{
    public static function id(): string
    {
        return 'flat-rate';
    }

    public static function compatibleCore(): string
    {
        return '^1.0';
    }

    public static function compatibleCommerce(): string
    {
        return '^0.1';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return ['id' => self::id(), 'version' => '1.0.0', 'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()], 'capabilities' => ['shipping.provider'], 'permissions' => ['shipping.read', 'shipping.write'], 'migrations' => [], 'routes' => []];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return ['flat_minor' => 30000, 'free_over_minor' => 500000];
    }

    public function boot(): void
    {
        // Stateless rate table: nothing to register at boot.
    }

    public function shutdown(): void
    {
    }

    /** @return array<string, \App\Contracts\PaymentGatewayInterface> */
    public function paymentGateways(): array
    {
        return [];
    }

    /** @return array<string, \App\Contracts\ShippingProviderInterface> */
    public function shippingProviders(): array
    {
        return ['flat' => $this];
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function getRates(array $shipment, array $ctx): array
    {
        $subtotal = Arr::int($shipment, 'subtotal_minor');
        $flat = Arr::int($ctx, 'flat_minor', 30000);
        $freeOver = Arr::int($ctx, 'free_over_minor', 500000);
        if ($subtotal >= $freeOver) {
            return [['id' => 'flat-free', 'label' => 'Free shipping', 'amount_minor' => 0]];
        }
        return [['id' => 'flat-std', 'label' => 'Flat rate', 'amount_minor' => $flat]];
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function createShipment(array $shipment, array $ctx): array
    {
        return ['shipment_id' => 'flat-' . bin2hex(random_bytes(6)), 'state' => 'created'];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function cancelShipment(string $shipmentId, array $ctx): array
    {
        return ['shipment_id' => $shipmentId, 'state' => 'cancelled'];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function track(string $shipmentId, array $ctx): array
    {
        return ['shipment_id' => $shipmentId, 'state' => 'in_transit'];
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function createReturnLabel(array $shipment, array $ctx): array
    {
        return ['shipment_id' => 'flat-return-' . bin2hex(random_bytes(6)), 'state' => 'created'];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function normalizeStatus(string $providerStatus, array $ctx = []): array
    {
        return ['provider_status' => $providerStatus, 'status' => $providerStatus === 'delivered' ? 'delivered' : 'unknown'];
    }
}
