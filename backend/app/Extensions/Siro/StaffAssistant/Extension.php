<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StaffAssistant;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/StaffAssistantPolicy.php';
require_once __DIR__ . '/src/StaffAssistantService.php';
require_once __DIR__ . '/src/StaffAssistantController.php';

/**
 * Read-only staff assistant: answers from explicitly scoped order and product
 * facts. It cannot mutate financial or catalog state.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/staff-assistant';
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
            'capabilities' => ['staff-assistant:read', 'staff-assistant:ask'],
            'permissions' => ['staff-assistant:read', 'staff-assistant:ask'],
            'migrations' => [],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => [
                    'label' => 'Staff assistant',
                    'section' => 'Growth',
                ],
                'storefront' => [],
            ],
            'admin' => [
                'label' => 'Staff assistant',
                'section' => 'Growth',
                'panels' => [
                    ['key' => 'chat', 'title' => 'Staff assistant', 'type' => 'assistant', 'endpoint' => 'admin/ask', 'permission' => 'staff-assistant:ask'],
                    ['key' => 'status', 'title' => 'Assistant status', 'type' => 'stats', 'endpoint' => 'admin/status', 'permission' => 'staff-assistant:read'],
                ],
            ],
            'license' => ['required' => false, 'sku' => 'siro-staff-assistant', 'features' => ['staff-assistant']],
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
