<?php

declare(strict_types=1);

namespace App\Extensions\Siro\AbandonedCart;

use App\Contracts\ExtensionInterface;
use App\Support\Arr;
use Siro\Core\Event;

require_once __DIR__ . '/src/AbandonedCartService.php';
require_once __DIR__ . '/src/AbandonedCartController.php';

/**
 * Free Abandoned Cart (siro/abandoned-cart).
 *
 * Captures abandoned carts via the platform `cart.abandoned` event, stores a
 * totals snapshot, and sends one consent-gated reminder email with a
 * recovery coupon. No Pro code lives here: multi-step, SMS, AI copy,
 * reporting and segmentation are in siro/abandoned-cart-pro.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/abandoned-cart';
    public const VERSION = '1.0.0';

    /** @var array<string, int> */
    private const CONFIG = [
        'coupon_percent_off' => AbandonedCartService::COUPON_PERCENT_OFF,
        'coupon_valid_days' => AbandonedCartService::COUPON_VALID_DAYS,
        'coupon_usage_limit' => AbandonedCartService::COUPON_USAGE_LIMIT,
    ];

    /** @var int|null Handle of the registered cart.abandoned listener. */
    private static ?int $listenerHandle = null;

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
            'capabilities' => ['abandoned-cart:read', 'abandoned-cart:manage', 'coupon:create'],
            'permissions' => ['abandoned-cart:manage', 'coupon:create'],
            'migrations' => [
                'migrations/20261126000041_create_ext_abandoned_cart_snapshots.php',
                'migrations/20261126000042_create_ext_abandoned_cart_deliveries.php',
            ],
            'routes' => ['routes.php'],
            'slots' => [
                'admin' => [
                    'label' => 'Abandoned cart',
                    'section' => 'Growth',
                ],
                'storefront' => [],
            ],
            'config' => self::CONFIG,
            'admin' => [
                'label' => 'Abandoned cart',
                'section' => 'Growth',
                'panels' => [
                    ['key' => 'overview', 'title' => 'Overview', 'type' => 'stats', 'endpoint' => 'admin/overview', 'permission' => 'abandoned-cart:manage'],
                    ['key' => 'snapshots', 'title' => 'Abandoned carts', 'type' => 'table', 'endpoint' => 'admin/snapshots', 'permission' => 'abandoned-cart:manage'],
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
        // boot() can run on every request in long-lived runtimes: off-then-on
        // by handle keeps exactly one listener (Event::off with a handle
        // removes only this listener, never core listeners) and survives
        // Event::flush() in tests, which would strand a static $booted flag.
        if (self::$listenerHandle !== null) {
            Event::off('cart.abandoned', self::$listenerHandle);
        }
        self::$listenerHandle = Event::on('cart.abandoned', static function (mixed $payload): void {
            if (!is_array($payload)) return;
            (new AbandonedCartService())->onCartAbandoned(Arr::row($payload));
        });
    }

    public function shutdown(): void
    {
        if (self::$listenerHandle !== null) {
            Event::off('cart.abandoned', self::$listenerHandle);
            self::$listenerHandle = null;
        }
    }
}
