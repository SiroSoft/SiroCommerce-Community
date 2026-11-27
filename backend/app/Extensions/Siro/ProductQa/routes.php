<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/product-qa/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * Storefront routes are anonymous; the loader still enforces Idempotency-Key
 * on POST/PUT. Admin routes require the manifest permission. Every query is
 * filtered by CommerceScope (store_id + channel_id).
 */

use App\Extensions\Siro\ProductQa\ProductQaController;

require_once __DIR__ . '/src/ProductQaService.php';
require_once __DIR__ . '/src/ProductQaController.php';

return [
    ['GET', 'public', [ProductQaController::class, 'publicList'], ['throttle:120,1']],
    ['POST', 'public', [ProductQaController::class, 'submit'], ['throttle:20,1', 'public']], // public anonymous submit: no auth by design
    ['GET', 'admin', [ProductQaController::class, 'adminList'], ['cap:product-qa:manage', 'throttle:60,1']],
    ['PUT', 'admin/{id}', [ProductQaController::class, 'adminUpdate'], ['cap:product-qa:manage', 'throttle:20,1']],
];
