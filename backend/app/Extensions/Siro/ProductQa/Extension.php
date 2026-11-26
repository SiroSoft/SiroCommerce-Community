<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ProductQa;

use App\Contracts\ExtensionInterface;

require_once __DIR__ . '/src/ProductQaService.php';
require_once __DIR__ . '/src/ProductQaController.php';

/**
 * Free product Q&A extension: shoppers submit questions on the PDP, admins
 * moderate and answer them. Rows live in ext_product_qa and are scoped by
 * store_id + channel_id on every read and write.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/product-qa';
    public const VERSION = '1.0.0';

    /** @var array<string, int> */
    private const CONFIG = [
        'max_question_length' => 1000,
        'max_answer_length' => 2000,
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
            'capabilities' => ['product-qa:read', 'product-qa:submit', 'product-qa:manage'],
            'permissions' => ['product-qa:manage'],
            'migrations' => ['migrations/20261123000002_create_ext_product_qa.php'],
            'routes' => ['routes.php'],
            'config' => self::CONFIG,
            'slots' => [
                'admin' => [
                    'label' => 'Product Q&A',
                    'section' => 'Commerce',
                ],
                'storefront' => ['pdp'],
            ],
            'admin' => [
                'label' => 'Product Q&A',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'questions', 'title' => 'Product questions', 'type' => 'table', 'endpoint' => 'admin', 'permission' => 'product-qa:manage'],
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
