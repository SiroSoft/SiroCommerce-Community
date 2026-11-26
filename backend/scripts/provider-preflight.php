<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

require_once dirname(__DIR__) . '/app/Support/EvidenceStatus.php';

use App\Support\ProviderReadiness;
use App\Support\ProviderCertification;

$result = ProviderReadiness::check();
$result['local_fixtures'] = ProviderCertification::localFixtures();
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
exit(($result['ready'] ?? false) === true ? 0 : 2);
