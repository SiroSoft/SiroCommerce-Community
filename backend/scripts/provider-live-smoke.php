<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\ProviderCertification;

$result = ProviderCertification::run();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
// NOT_PROVEN is not a successful certification. Live provider routes must not
// become release evidence merely because the probe was configured to run.
$status = $result['status'] ?? '';
exit($status === 'PASS' ? 0 : ($status === 'BLOCKED_EXTERNAL' ? 2 : 1));
