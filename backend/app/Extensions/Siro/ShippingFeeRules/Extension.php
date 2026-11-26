<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ShippingFeeRules;

use App\Contracts\ExtensionInterface;
use App\Modules\Shipping\Application\ShippingFeeAdjusterRegistry;
use App\Modules\Shipping\Contracts\ShippingFeeAdjuster;
use App\Support\Arr;
use App\Support\CommerceScope;

require_once __DIR__ . '/src/ShippingFeeRuleService.php';
require_once __DIR__ . '/src/ShippingFeeRuleController.php';

/**
 * First free Siro extension: store/channel scoped shipping fee and COD rules.
 * Package code lives under src/ (namespace App\Extensions\Siro\ShippingFeeRules)
 * per EXTENSION_ECOSYSTEM_SPEC; the two requires keep the package self-contained
 * without editing composer.json or core.
 */
final class Extension implements ExtensionInterface, ShippingFeeAdjuster
{
    public const EXTENSION_ID = 'siro/shipping-fee-rules';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string> */
    private const CONFIG = [
        'default_method' => 'standard',
        'default_fee_minor' => 0,
        'default_free_over_minor' => 0,
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
            'capabilities' => ['shipping-fee:read', 'shipping-fee:manage'],
            'permissions' => ['shipping-fee:manage'],
            'migrations' => ['migrations/20261122000001_create_ext_shipping_fee_rules.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'admin' => [
                'label' => 'Shipping fees',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'overview', 'title' => 'Overview', 'type' => 'stats', 'endpoint' => 'admin/overview', 'permission' => 'shipping-fee:manage'],
                    ['key' => 'rules', 'title' => 'Shipping fee rules', 'type' => 'table', 'endpoint' => 'admin/overview', 'permission' => 'shipping-fee:manage'],
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
        ShippingFeeAdjusterRegistry::register(self::EXTENSION_ID, $this);
    }

    public function shutdown(): void
    {
        ShippingFeeAdjusterRegistry::unregister(self::EXTENSION_ID);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function fee(array $scope, int $subtotalMinor, string $method): int
    {
        $storeId = Arr::str($scope, 'store_id', 'default');
        $channelId = Arr::str($scope, 'channel_id', 'web');
        $locale = Arr::str($scope, 'locale', 'en');
        $currency = strtoupper(Arr::str($scope, 'currency', 'VND'));

        return (new ShippingFeeRuleService())->feeFor(
            new CommerceScope(
                $storeId !== '' ? $storeId : 'default',
                $channelId !== '' ? $channelId : 'web',
                $locale !== '' ? $locale : 'en',
                $currency !== '' ? $currency : 'VND',
            ),
            $subtotalMinor,
            $method,
        );
    }
}
