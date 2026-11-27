<?php

declare(strict_types=1);

namespace App\Extensions\Siro\LicensePortal;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/LicensePortalService.php';
require_once __DIR__ . '/src/LicensePortalController.php';

final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/license-portal';

    public const VERSION = '1.0.0';

    /** @var array<string, mixed> */
    private const CONFIG = [
        'token_ttl_hours' => 168,
        'max_token_ttl_hours' => 720,
        'sku_map' => [
            'siro-pro-license' => 'siro-pro',
        ],
        'license_sku_prefixes' => [
            'siro-pro-',
            'siro-license-',
        ],
    ];

    private static bool $booted = false;

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
            'capabilities' => ['license-portal:read', 'license-portal:manage'],
            'permissions' => ['license-portal:manage'],
            'migrations' => ['migrations/20261126000001_create_ext_license_portal_tables.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'license' => [
                'required' => false,
                'sku' => 'siro-license-portal',
                'features' => ['license-portal'],
            ],
            'admin' => [
                'label' => 'License portal',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'overview', 'title' => 'Delivery overview', 'type' => 'stats', 'endpoint' => 'admin/overview', 'permission' => 'license-portal:manage'],
                    ['key' => 'deliveries', 'title' => 'License deliveries', 'type' => 'table', 'endpoint' => 'admin/overview', 'permission' => 'license-portal:manage'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
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
        LicensePortalService::listen(true);
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        \Siro\Core\Event::on('order.paid', [LicensePortalService::class, 'onOrderPaid']);
        \Siro\Core\Event::on('order.transitioned', [LicensePortalService::class, 'onOrderTransitioned']);
    }

    public function shutdown(): void
    {
        LicensePortalService::listen(false);
    }
}
