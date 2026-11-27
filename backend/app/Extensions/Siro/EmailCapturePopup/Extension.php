<?php

declare(strict_types=1);

namespace App\Extensions\Siro\EmailCapturePopup;

use App\Contracts\ExtensionInterface;
use App\Modules\Mail\Application\MailTemplateRegistry;

require_once __DIR__ . '/src/EmailCaptureService.php';
require_once __DIR__ . '/src/EmailCaptureController.php';

/**
 * Free email-capture popup.
 * Package code lives under src/ (namespace App\Extensions\Siro\EmailCapturePopup)
 * per EXTENSION_ECOSYSTEM_SPEC; no core files are required or edited.
 *
 * Flow: the storefront popup posts the opt-in to core
 * POST /api/consent/marketing first, then POSTs here. This extension stores
 * the email in its own ext_* table and sends the welcome mail (template
 * `welcome`, via MailTemplateRegistry) only when
 * ConsentService::hasMarketingConsent() is true.
 */
final class Extension implements ExtensionInterface
{
    public const EXTENSION_ID = 'siro/email-capture-popup';
    public const VERSION = '1.0.0';

    /** @var array<string, int|string|bool> */
    public const CONFIG = [
        'default_delay_ms' => 8000,
        'default_coupon_enabled' => true,
        'default_coupon_percent' => 10,
        'default_active' => true,
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
            'capabilities' => ['email-capture:read', 'email-capture:manage', 'coupon:create'],
            'permissions' => ['email-capture:manage', 'coupon:create'],
            'migrations' => ['migrations/20261126000021_create_ext_email_capture_subscribers.php'],
            'routes' => ['routes.php'],
            'slots' => [
                'admin' => [
                    'label' => 'Email capture popup',
                    'section' => 'Commerce',
                ],
                'storefront' => ['footer'],
            ],
            'config' => self::CONFIG,
            'admin' => [
                'label' => 'Email capture popup',
                'section' => 'Commerce',
                'panels' => [
                    ['key' => 'stats', 'title' => 'Subscriber stats', 'type' => 'stats', 'endpoint' => 'admin/stats', 'permission' => 'email-capture:manage'],
                    ['key' => 'subscribers', 'title' => 'Subscribers', 'type' => 'table', 'endpoint' => 'admin/subscribers', 'permission' => 'email-capture:manage'],
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
        MailTemplateRegistry::register(
            EmailCaptureService::EVENT_SUBSCRIBED,
            'welcome',
            ['customer_name', 'coupon_code']
        );
    }

    public function shutdown(): void
    {
    }
}
