<?php

declare(strict_types=1);

namespace App\Extensions\Siro\FreeShippingBanner;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/FreeShippingBannerService.php';
require_once __DIR__ . '/src/FreeShippingBannerController.php';

/**
 * Free store/channel scoped free-shipping threshold banner.
 * Package code lives under src/ (namespace App\Extensions\Siro\FreeShippingBanner)
 * per EXTENSION_ECOSYSTEM_SPEC; no core files are required or edited.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/free-shipping-banner';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string|bool> */
    private const CONFIG = [
        'default_threshold_minor' => 0,
        'default_message' => 'Free shipping on qualifying orders',
        'default_active' => false,
        'default_currency' => 'VND',
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
        return '^0.3';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'id' => self::id(),
            'version' => self::VERSION,
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => ['free-shipping:read', 'free-shipping:manage'],
            'permissions' => ['free-shipping:manage'],
            'migrations' => ['migrations/20261123000001_create_ext_free_shipping_banner.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'admin' => [
                'label' => 'Free shipping banner',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'settings', 'title' => 'Banner settings', 'type' => 'table', 'endpoint' => 'admin/settings', 'permission' => 'free-shipping:manage'],
                ],
            ],
        ];
    }

    /** @return array<string, int|string|bool> */
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
    }

    public function shutdown(): void
    {
    }
}
