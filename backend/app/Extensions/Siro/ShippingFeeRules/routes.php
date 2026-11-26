<?php

declare(strict_types=1);

/**
 * Mounted by ExtensionRouteLoader under /api/ext/siro/shipping-fee-rules/.
 * Rows: [method, relative path, [controller, action], middleware tokens].
 * All reads/writes are scoped by CommerceScope (store_id + channel_id) and
 * gated by the manifest permission; mutations also get Idempotency-Key
 * enforcement from the loader automatically.
 */

use App\Extensions\Siro\ShippingFeeRules\ShippingFeeRuleController;

require_once __DIR__ . '/src/ShippingFeeRuleService.php';
require_once __DIR__ . '/src/ShippingFeeRuleController.php';

return [
    ['GET', 'rules', [ShippingFeeRuleController::class, 'index'], ['cap:shipping-fee:manage', 'throttle:60,1']],
    ['POST', 'rules', [ShippingFeeRuleController::class, 'store'], ['cap:shipping-fee:manage', 'throttle:20,1']],
    ['GET', 'admin/overview', [ShippingFeeRuleController::class, 'overview'], ['cap:shipping-fee:manage', 'throttle:60,1']],
];
