<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\PaymentLaunchCertification;

$report = PaymentLaunchCertification::run();
$output = '';
foreach ($argv as $arg) if (str_starts_with($arg, '--output=')) $output = substr($arg, 9);
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($output !== '') file_put_contents($output, $json, LOCK_EX);
fwrite(STDOUT, $json);
exit(($report['status'] ?? 'FAIL') === 'PASS' ? 0 : (($report['status'] ?? '') === 'BLOCKED_EXTERNAL' ? 2 : 1));
