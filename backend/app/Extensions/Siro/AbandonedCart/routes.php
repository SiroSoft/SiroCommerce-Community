<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/abandoned-cart/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * admin/* requires the manifest permission; mutations also get
 * Idempotency-Key enforcement from the loader. Every mutating row carries
 * cap: on the same line (QIT route-auth check reads raw lines).
 */

use App\Extensions\Siro\AbandonedCart\AbandonedCartController;

require_once __DIR__ . '/src/AbandonedCartService.php';
require_once __DIR__ . '/src/AbandonedCartController.php';

return [
    ['GET', 'admin/overview', [AbandonedCartController::class, 'overview'], ['cap:abandoned-cart:manage', 'throttle:60,1']],
    ['GET', 'admin/snapshots', [AbandonedCartController::class, 'snapshots'], ['cap:abandoned-cart:manage', 'throttle:60,1']],
    ['GET', 'admin/deliveries', [AbandonedCartController::class, 'deliveries'], ['cap:abandoned-cart:manage', 'throttle:60,1']],
    ['POST', 'coupons', [AbandonedCartController::class, 'createCoupon'], ['cap:coupon:create', 'throttle:20,1']],
];
