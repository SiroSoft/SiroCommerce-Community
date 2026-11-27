<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/sticky-add-to-cart/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * admin/* requires the manifest permission; public is unauthenticated and
 * throttled. Mutations also get Idempotency-Key enforcement from the loader.
 */

use App\Extensions\Siro\StickyAddToCart\StickyAddToCartController;

require_once __DIR__ . '/src/StickyAddToCartService.php';
require_once __DIR__ . '/src/StickyAddToCartController.php';

return [
    ['GET', 'admin/settings', [StickyAddToCartController::class, 'settings'], ['cap:sticky-atc:manage', 'throttle:60,1']],
    ['GET', 'admin/stats', [StickyAddToCartController::class, 'stats'], ['cap:sticky-atc:manage', 'throttle:60,1']],
    ['PUT', 'admin/settings', [StickyAddToCartController::class, 'update'], ['cap:sticky-atc:manage', 'throttle:20,1']],
    ['GET', 'public', [StickyAddToCartController::class, 'publicSettings'], ['throttle:120,1']],
];
