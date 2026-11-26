<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/store-locator/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * The public route is unauthenticated (storefront footer) and throttled; it
 * only returns active locations. Admin routes require the manifest
 * store-locator:manage permission. Mutations also get Idempotency-Key
 * enforcement from the loader. Every query is filtered by CommerceScope
 * (store_id + channel_id).
 */

use App\Extensions\Siro\StoreLocator\StoreLocatorController;

require_once __DIR__ . '/src/StoreLocatorService.php';
require_once __DIR__ . '/src/StoreLocatorController.php';

return [
    ['GET', 'admin', [StoreLocatorController::class, 'adminList'], ['cap:store-locator:manage', 'throttle:60,1']],
    ['POST', 'admin', [StoreLocatorController::class, 'adminCreate'], ['cap:store-locator:manage', 'throttle:20,1']],
    ['PUT', 'admin/{id}', [StoreLocatorController::class, 'adminUpdate'], ['cap:store-locator:manage', 'throttle:20,1']],
    ['DELETE', 'admin/{id}', [StoreLocatorController::class, 'adminDelete'], ['cap:store-locator:manage', 'throttle:20,1']],
    ['GET', 'public', [StoreLocatorController::class, 'publicList'], ['throttle:120,1']],
];
