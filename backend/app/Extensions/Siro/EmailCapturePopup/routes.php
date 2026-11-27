<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/email-capture-popup/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * admin/* requires the manifest permission; public subscribe/config are
 * unauthenticated and throttled. Mutations also get Idempotency-Key
 * enforcement from the loader.
 */

use App\Extensions\Siro\EmailCapturePopup\EmailCaptureController;

require_once __DIR__ . '/src/EmailCaptureService.php';
require_once __DIR__ . '/src/EmailCaptureController.php';

return [
    ['GET', 'public', [EmailCaptureController::class, 'publicConfig'], ['throttle:120,1']],
    ['POST', 'subscribe', [EmailCaptureController::class, 'subscribe'], ['throttle:20,1', 'public']],
    ['GET', 'admin/stats', [EmailCaptureController::class, 'stats'], ['cap:email-capture:manage', 'throttle:60,1']],
    ['GET', 'admin/subscribers', [EmailCaptureController::class, 'subscribers'], ['cap:email-capture:manage', 'throttle:60,1']],
    ['DELETE', 'admin/subscribers/{id}', [EmailCaptureController::class, 'deleteSubscriber'], ['cap:email-capture:manage', 'throttle:20,1']],
    ['POST', 'coupons', [EmailCaptureController::class, 'createCoupon'], ['cap:coupon:create', 'throttle:20,1']],
];
