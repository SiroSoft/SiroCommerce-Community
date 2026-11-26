<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Contracts\ExtensionInterface;

/**
 * Lifecycle fixture for Pro artifact tests. The zip itself carries the
 * App\Extensions provider required by the package verifier; this class only
 * supplies a discoverable manifest so registry state can be exercised without
 * installing into the real backend tree.
 */
final class ProArtifactFixtureExtension implements ExtensionInterface
{
    public static string $version = '1.0.0';

    public static function id(): string
    {
        return 'siro/pro-fixture';
    }

    public static function compatibleCore(): string
    {
        return '^1.0';
    }

    public static function compatibleCommerce(): string
    {
        return '^0.2';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'id' => self::id(),
            'version' => self::$version,
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => [],
            'permissions' => [],
            'migrations' => [],
            'routes' => [],
            'license' => ['required' => true, 'sku' => 'siro-pro-fixture'],
        ];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [];
    }

    public function boot(): void
    {
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
        return [];
    }
}
