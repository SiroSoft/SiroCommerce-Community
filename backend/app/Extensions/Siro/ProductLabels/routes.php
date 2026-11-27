<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/product-labels/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * admin/* requires the manifest permission; public is unauthenticated and
 * throttled. Mutations also get Idempotency-Key enforcement from the loader.
 */

use App\Extensions\Siro\ProductLabels\ProductLabelsController;

require_once __DIR__ . '/src/RuleEvaluator.php';
require_once __DIR__ . '/src/ProductLabelsService.php';
require_once __DIR__ . '/src/ProductLabelsController.php';

return [
    ['GET', 'admin/stats', [ProductLabelsController::class, 'stats'], ['cap:product-labels:manage', 'throttle:60,1']],
    ['GET', 'admin/rules', [ProductLabelsController::class, 'index'], ['cap:product-labels:manage', 'throttle:60,1']],
    ['POST', 'admin/rules', [ProductLabelsController::class, 'store'], ['cap:product-labels:manage', 'throttle:20,1']],
    ['GET', 'admin/rules/{id}', [ProductLabelsController::class, 'show'], ['cap:product-labels:manage', 'throttle:60,1']],
    ['PUT', 'admin/rules/{id}', [ProductLabelsController::class, 'update'], ['cap:product-labels:manage', 'throttle:20,1']],
    ['DELETE', 'admin/rules/{id}', [ProductLabelsController::class, 'destroy'], ['cap:product-labels:manage', 'throttle:20,1']],
    ['GET', 'public/labels', [ProductLabelsController::class, 'publicLabels'], ['throttle:120,1']],
];
