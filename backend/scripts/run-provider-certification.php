<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\ProviderSandboxCertification;

$result = ProviderSandboxCertification::run();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['status'] === 'LOCAL_PASS' ? 0 : 1);
