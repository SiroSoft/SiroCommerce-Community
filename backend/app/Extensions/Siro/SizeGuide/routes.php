<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/size-guide/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * Admin routes require the manifest permission; the public chart route is
 * anonymous and throttled. The loader still enforces Idempotency-Key on PUT.
 * Every query is filtered by CommerceScope (store_id + channel_id).
 */

use App\Extensions\Siro\SizeGuide\SizeGuideController;

require_once __DIR__ . '/src/SizeGuideService.php';
require_once __DIR__ . '/src/SizeGuideController.php';

return [
    ['GET', 'admin/charts', [SizeGuideController::class, 'adminList'], ['cap:size-guide:manage', 'throttle:60,1']],
    ['PUT', 'admin/charts/{category}', [SizeGuideController::class, 'upsert'], ['cap:size-guide:manage', 'throttle:20,1']],
    ['GET', 'public', [SizeGuideController::class, 'publicChart'], ['throttle:120,1']],
];
