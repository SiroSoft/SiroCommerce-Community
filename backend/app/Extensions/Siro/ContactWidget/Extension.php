<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ContactWidget;

use App\Contracts\ExtensionInterface;
use App\Support\Arr;
use App\Support\CommerceScope;

require_once __DIR__ . '/src/ContactWidgetService.php';
require_once __DIR__ . '/src/ContactWidgetController.php';

/**
 * Free Siro extension: store/channel scoped floating contact widget.
 * Package code lives under src/ (namespace App\Extensions\Siro\ContactWidget)
 * per EXTENSION_ECOSYSTEM_SPEC; the requires keep the package self-contained
 * without editing composer.json or core.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/contact-widget';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string> */
    private const CONFIG = [
        'default_label' => 'Contact us',
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
            'capabilities' => ['contact-widget:read', 'contact-widget:manage'],
            'permissions' => ['contact-widget:manage'],
            'migrations' => ['migrations/20261123000003_create_ext_contact_widget.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => ['label' => 'Contact widget', 'section' => 'Commerce'],
                'storefront' => ['footer'],
            ],
            'admin' => [
                'label' => 'Contact widget',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'settings', 'title' => 'Contact widget', 'type' => 'stats', 'endpoint' => 'admin/settings', 'permission' => 'contact-widget:manage'],
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
        // No listeners: the widget reads its scoped row through its own routes.
    }

    public function shutdown(): void
    {
    }

    /**
     * Convenience scope builder for consumers that already hold raw values.
     */
    public static function scope(string $storeId, string $channelId, string $locale = 'en', string $currency = 'VND'): CommerceScope
    {
        return new CommerceScope(
            $storeId !== '' ? $storeId : 'default',
            $channelId !== '' ? $channelId : 'web',
            $locale !== '' ? $locale : 'en',
            $currency !== '' ? $currency : 'VND',
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    public static function fromArrayScope(array $scope): CommerceScope
    {
        return self::scope(
            Arr::str($scope, 'store_id', 'default'),
            Arr::str($scope, 'channel_id', 'web'),
            Arr::str($scope, 'locale', 'en'),
            Arr::str($scope, 'currency', 'VND'),
        );
    }
}
