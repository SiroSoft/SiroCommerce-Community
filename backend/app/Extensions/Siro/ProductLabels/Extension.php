<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductLabels;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/RuleEvaluator.php';
require_once __DIR__ . '/src/ProductLabelsService.php';
require_once __DIR__ . '/src/ProductLabelsController.php';

/**
 * Store/channel scoped product label / badge rules.
 * Package code lives under src/ (namespace App\Extensions\Siro\ProductLabels)
 * per EXTENSION_ECOSYSTEM_SPEC; no core files are required or edited.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/product-labels';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string|bool> */
    private const CONFIG = [
        'default_new_days' => 30,
        'max_labels_per_product' => 3,
        'default_priority' => 100,
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
            'capabilities' => ['product-labels:read', 'product-labels:manage'],
            'permissions' => ['product-labels:manage'],
            'migrations' => [
                'migrations/20261126000011_create_ext_product_label_rules.php',
                'migrations/20261126000012_create_ext_product_label_audit.php',
            ],
            'routes' => ['routes.php'],
            'slots' => [
                'admin' => [
                    'label' => 'Product labels',
                    'section' => 'Commerce',
                ],
                'storefront' => ['product-card', 'pdp.title'],
            ],
            'config' => self::CONFIG,
            'admin' => [
                'label' => 'Product labels',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'stats', 'title' => 'Label stats', 'type' => 'stats', 'endpoint' => 'admin/stats', 'permission' => 'product-labels:manage'],
                    ['key' => 'rules', 'title' => 'Label rules', 'type' => 'table', 'endpoint' => 'admin/rules', 'permission' => 'product-labels:manage'],
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
