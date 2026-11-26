<?php

declare(strict_types=1);

namespace App\Extensions\Siro\SocialShare;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/SocialShareService.php';
require_once __DIR__ . '/src/SocialShareController.php';

/**
 * Free Siro extension: store/channel scoped product social-share bar for the
 * PDP. Package code lives under src/ (namespace App\Extensions\Siro\SocialShare)
 * per EXTENSION_ECOSYSTEM_SPEC; no core files are required or edited.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/social-share';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string|bool> */
    private const CONFIG = [
        'default_label' => 'Share this product',
        'default_enabled' => false,
        'slot' => 'pdp',
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
        return '^0.2';
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'id' => self::id(),
            'version' => self::VERSION,
            'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()],
            'capabilities' => ['social-share:read', 'social-share:manage'],
            'permissions' => ['social-share:manage'],
            'migrations' => ['migrations/20261124000001_create_ext_social_share.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => ['label' => 'Social share', 'section' => 'Commerce'],
                'storefront' => ['pdp'],
            ],
            'admin' => [
                'label' => 'Social share',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'settings', 'title' => 'Social share settings', 'type' => 'table', 'endpoint' => 'admin/settings', 'permission' => 'social-share:manage'],
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
        // No listeners: the storefront reads its scoped row through public.
    }

    public function shutdown(): void
    {
    }
}
