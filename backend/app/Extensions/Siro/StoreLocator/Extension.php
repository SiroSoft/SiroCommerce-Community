<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StoreLocator;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/StoreLocatorService.php';
require_once __DIR__ . '/src/StoreLocatorController.php';

/**
 * Free Siro extension: store/channel scoped store locator with an admin CRUD
 * table and a footer store list. Package code lives under src/ (namespace
 * App\Extensions\Siro\StoreLocator) per EXTENSION_ECOSYSTEM_SPEC; the requires
 * keep the package self-contained without editing composer.json or core.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/store-locator';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string> */
    private const CONFIG = [
        'default_label' => 'Stores',
        'slot' => 'footer',
    ];

    public static function id(): string
    {
        return self::EXTENSION_ID;
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
        return [
            'id' => self::id(),
            'version' => self::VERSION,
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => ['store-locator:read', 'store-locator:manage'],
            'permissions' => ['store-locator:manage'],
            'migrations' => ['migrations/20261124000003_create_ext_store_locator.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => ['label' => 'Store locator', 'section' => 'Commerce'],
                'storefront' => ['footer'],
            ],
            'admin' => [
                'label' => 'Store locator',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'locations', 'title' => 'Store locations', 'type' => 'table', 'endpoint' => 'admin', 'permission' => 'store-locator:manage'],
                ],
            ],
        ];
    }

    /** @return array<string, int|string> */
    public function config(): array
    {
        return self::CONFIG;
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

    public function boot(): void
    {
        // No listeners: the locator reads its scoped rows through its own routes.
    }

    public function shutdown(): void
    {
    }
}
