<?php

declare(strict_types=1);

use App\Extensions\Siro\ShopperAssistant\ShopperAssistantController;

require_once __DIR__ . '/src/ShopperAssistantService.php';
require_once __DIR__ . '/src/ShopperAssistantController.php';

return [
    ['POST', 'public/ask', [ShopperAssistantController::class, 'ask'], ['throttle:20,1', 'optional-auth']],
];
