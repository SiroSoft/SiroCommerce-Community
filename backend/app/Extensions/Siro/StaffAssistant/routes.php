<?php

declare(strict_types=1);

use App\Extensions\Siro\StaffAssistant\StaffAssistantController;

require_once __DIR__ . '/src/StaffAssistantPolicy.php';
require_once __DIR__ . '/src/StaffAssistantService.php';
require_once __DIR__ . '/src/StaffAssistantController.php';

return [
    ['GET', 'admin/status', [StaffAssistantController::class, 'status'], ['cap:staff-assistant:read', 'throttle:60,1']],
    ['POST', 'admin/ask', [StaffAssistantController::class, 'ask'], ['cap:staff-assistant:ask', 'throttle:20,1']],
];
