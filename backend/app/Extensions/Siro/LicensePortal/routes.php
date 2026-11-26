<?php

declare(strict_types=1);

use App\Extensions\Siro\LicensePortal\LicensePortalController;

require_once __DIR__ . '/src/LicensePortalService.php';
require_once __DIR__ . '/src/LicensePortalController.php';

return [
    ['GET', 'download/{token}', [LicensePortalController::class, 'download'], ['throttle:30,1']],
    ['GET', 'admin/deliveries', [LicensePortalController::class, 'index'], ['cap:license-portal:manage', 'throttle:60,1']],
    ['GET', 'admin/overview', [LicensePortalController::class, 'overview'], ['cap:license-portal:manage', 'throttle:60,1']],
    ['POST', 'admin/deliveries', [LicensePortalController::class, 'assign'], ['cap:license-portal:manage', 'throttle:20,1']],
    ['POST', 'admin/deliveries/{id}/revoke', [LicensePortalController::class, 'revoke'], ['cap:license-portal:manage', 'throttle:20,1']],
    ['POST', 'admin/deliveries/{id}/rotate', [LicensePortalController::class, 'rotate'], ['cap:license-portal:manage', 'throttle:20,1']],
];
