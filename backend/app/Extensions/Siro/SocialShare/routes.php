<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/social-share/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * admin/* requires the manifest permission; public is unauthenticated and
 * throttled. Mutations also get Idempotency-Key enforcement from the loader.
 */

use App\Extensions\Siro\SocialShare\SocialShareController;

require_once __DIR__ . '/src/SocialShareService.php';
require_once __DIR__ . '/src/SocialShareController.php';

return [
    ['GET', 'admin/settings', [SocialShareController::class, 'settings'], ['cap:social-share:manage', 'throttle:60,1']],
    ['PUT', 'admin/settings', [SocialShareController::class, 'update'], ['cap:social-share:manage', 'throttle:20,1']],
    ['GET', 'public', [SocialShareController::class, 'publicSettings'], ['throttle:120,1']],
];
