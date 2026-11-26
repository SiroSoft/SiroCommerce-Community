<?php

declare(strict_types=1);

namespace App\Extensions\Siro\SizeGuide;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/SizeGuideService.php';
require_once __DIR__ . '/src/SizeGuideController.php';

/**
 * Free size-guide extension: one chart per product category, scoped by
 * store_id + channel_id, rendered as a storefront PDP button/modal.
 * Rows live in ext_size_guide_charts and every read/write filters scope.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/size-guide';
    public const VERSION = '1.0.0';

    /** @var array<string, int> */
    private const CONFIG = [
        'max_columns' => 8,
        'max_rows' => 50,
        'max_note_length' => 300,
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
            'capabilities' => ['size-guide:read', 'size-guide:manage'],
            'permissions' => ['size-guide:manage'],
            'migrations' => ['migrations/20261124000002_create_ext_size_guide.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => [
                    'label' => 'Size guide',
                    'section' => 'Commerce',
                ],
                'storefront' => ['pdp'],
            ],
            'admin' => [
                'label' => 'Size guide',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'charts', 'title' => 'Category size charts', 'type' => 'table', 'endpoint' => 'admin/charts', 'permission' => 'size-guide:manage'],
                ],
            ],
        ];
    }

    /** @return array<string, int> */
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
