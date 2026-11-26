<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Extension lifecycle (spec docs/07): register services, events/listeners,
 * admin pages (allowed slots only), CLI commands, migrations, config.
 * Must declare compatible sirocommerce + siro-core versions.
 */
interface ExtensionInterface
{
    public static function id(): string;

    public static function compatibleCore(): string;

    public static function compatibleCommerce(): string;

    /** @return array<string, mixed> */
    public function config(): array;

    /**
     * Called once when the extension becomes enabled at boot. Register event
     * listeners and provider bindings here. Must never throw for missing
     * optional dependencies — degrade to failed state instead.
     */
    public function boot(): void;

    /** Called when the extension is disabled: unregister listeners/bindings. */
    public function shutdown(): void;

    /** @return array<string, PaymentGatewayInterface> */
    public function paymentGateways(): array;

    /** @return array<string, ShippingProviderInterface> */
    public function shippingProviders(): array;
}
