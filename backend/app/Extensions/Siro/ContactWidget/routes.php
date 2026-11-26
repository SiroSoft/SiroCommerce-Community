<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/contact-widget/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * Reads/writes are scoped by CommerceScope (store_id + channel_id); admin
 * routes are gated by the manifest permission and PUT also gets
 * Idempotency-Key enforcement from the loader. The public route is
 * unauthenticated (storefront widget) and throttled.
 */

use App\Extensions\Siro\ContactWidget\ContactWidgetController;

require_once __DIR__ . '/src/ContactWidgetService.php';
require_once __DIR__ . '/src/ContactWidgetController.php';

return [
    ['GET', 'admin/settings', [ContactWidgetController::class, 'settings'], ['cap:contact-widget:manage', 'throttle:60,1']],
    ['PUT', 'admin/settings', [ContactWidgetController::class, 'save'], ['cap:contact-widget:manage', 'throttle:20,1']],
    ['GET', 'public', [ContactWidgetController::class, 'publicData'], ['throttle:120,1']],
];
