<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\OpenApiBreakingChanges;

$arguments = getopt('', ['baseline:', 'current::']);
$baselinePath = is_string($arguments['baseline'] ?? null) ? $arguments['baseline'] : null;
$currentPath = is_string($arguments['current'] ?? null) ? $arguments['current'] : dirname(__DIR__) . '/docs/openapi.json';

if ($baselinePath === null) {
    fwrite(STDERR, "api-breaking: --baseline=<release OpenAPI JSON> is required\n");
    exit(2);
}
if (!is_file($baselinePath) || !is_file($currentPath)) {
    fwrite(STDERR, "api-breaking: baseline and current files must exist\n");
    exit(2);
}

try {
    $baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    $current = json_decode((string) file_get_contents($currentPath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'api-breaking: invalid JSON: ' . $exception->getMessage() . "\n");
    exit(2);
}
if (!is_array($baseline) || !is_array($current)
    || !is_string($baseline['openapi'] ?? null) || !is_array($baseline['paths'] ?? null)
    || !is_string($current['openapi'] ?? null) || !is_array($current['paths'] ?? null)) {
    fwrite(STDERR, "api-breaking: baseline and current must be OpenAPI documents with openapi and paths\n");
    exit(2);
}

$changes = OpenApiBreakingChanges::detect($baseline, $current);
if ($changes !== []) {
    fwrite(STDERR, "api-breaking: BLOCK\n" . implode("\n", $changes) . "\n");
    exit(1);
}

echo "api-breaking: PASS (no consumer-breaking changes)\n";
