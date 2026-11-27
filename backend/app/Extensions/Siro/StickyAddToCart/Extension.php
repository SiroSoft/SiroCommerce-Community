<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StickyAddToCart;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/StickyAddToCartService.php';
require_once __DIR__ . '/src/StickyAddToCartController.php';

/**
 * Free store/channel scoped sticky Add to Cart bar for the PDP.
 * Package code lives under src/ (namespace App\Extensions\Siro\StickyAddToCart)
 * per EXTENSION_ECOSYSTEM_SPEC; no core files are required or edited.
 *
 * Settings persist in one scoped row of `ext_sticky_atc_settings`
 * (migration 20261126000001, the number PLAN §3.2 reserved for this plugin).
 * Manifest `config` carries the defaults only: no code in the repo seeds
 * manifest config into `configuration_values`, so a table is required for
 * admin settings to persist across requests. See PROGRESS.md decision 1.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/sticky-add-to-cart';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string|bool> */
    private const CONFIG = [
        'default_active' => false,
        'default_button_label' => 'Add to Cart',
        'default_show_price' => true,
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
            'capabilities' => ['sticky-atc:read', 'sticky-atc:manage'],
            'permissions' => ['sticky-atc:manage'],
            'migrations' => ['migrations/20261126000001_create_ext_sticky_atc_settings.php'],
            'routes' => ['routes.php'],
            'slots' => [
                'admin' => [
                    'label' => 'Sticky Add to Cart',
                    'section' => 'Commerce',
                ],
                'storefront' => ['pdp.below-atc'],
            ],
            'config' => self::CONFIG,
            'admin' => [
                'label' => 'Sticky Add to Cart',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'overview', 'title' => 'Overview', 'type' => 'stats', 'endpoint' => 'admin/stats', 'permission' => 'sticky-atc:manage'],
                    ['key' => 'settings', 'title' => 'Bar settings', 'type' => 'table', 'endpoint' => 'admin/settings', 'permission' => 'sticky-atc:manage'],
                ],
            ],
            'license' => ['required' => false, 'sku' => 'siro-sticky-add-to-cart', 'features' => ['sticky-atc']],
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
