<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ShopperAssistant;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/ShopperAssistantService.php';
require_once __DIR__ . '/src/ShopperAssistantController.php';

/**
 * Storefront shopper assistant: public, read-only product Q&A grounded on the
 * current scoped product facts. It never reads customer orders or identity.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/shopper-assistant';
    public const VERSION = '0.1.0';

    /** @var array<string, int> */
    private const CONFIG = [
        'max_question_length' => 1000,
        'max_cost_minor' => 0,
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
            'capabilities' => ['shopper-assistant:ask'],
            'permissions' => [],
            'migrations' => [],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => [],
                'storefront' => ['header'],
            ],
            'license' => ['required' => false, 'sku' => 'siro-shopper-assistant', 'features' => ['shopper-assistant']],
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
