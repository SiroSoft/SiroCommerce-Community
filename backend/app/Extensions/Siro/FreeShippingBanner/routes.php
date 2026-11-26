<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/free-shipping-banner/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * admin/* requires the manifest permission; public is unauthenticated and
 * throttled. Mutations also get Idempotency-Key enforcement from the loader.
 */

use App\Extensions\Siro\FreeShippingBanner\FreeShippingBannerController;

require_once __DIR__ . '/src/FreeShippingBannerService.php';
require_once __DIR__ . '/src/FreeShippingBannerController.php';

return [
    ['GET', 'admin/settings', [FreeShippingBannerController::class, 'settings'], ['cap:free-shipping:manage', 'throttle:60,1']],
    ['PUT', 'admin/settings', [FreeShippingBannerController::class, 'update'], ['cap:free-shipping:manage', 'throttle:20,1']],
    ['GET', 'public', [FreeShippingBannerController::class, 'publicSettings'], ['throttle:120,1']],
];
