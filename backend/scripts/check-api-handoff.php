<?php

declare(strict_types=1);

/** Strict API-1 handoff gate: route, OpenAPI, SDK and evidence records must agree. */
$base = dirname(__DIR__);
$matrixPath = $base . '/docs/api-handoff/coverage-matrix.json';
// Regenerate on every check so a newly added route cannot hide behind a stale matrix.
require $base . '/scripts/build-api-handoff-matrix.php';
$matrix = json_decode((string) file_get_contents($matrixPath), true, 512, JSON_THROW_ON_ERROR);
$openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
$public = json_decode((string) file_get_contents($base . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
if ($openapi !== $public) throw new RuntimeException('OpenAPI source/public copies differ');

$sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');
$sdkOperations = [];
preg_match_all("~\\b[a-z][A-Za-z0-9_]*\\(options: ApiRequestOptions(?:<[^>]+>)? = \{\}\): Promise<[^\\n]+?\\{ return this\\.request<[^\\n]+?\\('([A-Z]+)', '([^']+)'~", $sdk, $sdkMatches, PREG_SET_ORDER);
foreach ($sdkMatches as $match) $sdkOperations[$match[1] . ' ' . $match[2]] = true;
$counts = ['PASS' => 0, 'BLOCKED' => 0, 'BLOCKED_EXTERNAL' => 0, 'SKIP' => 0];
$failures = [];
foreach ($matrix['routes'] as $key => $route) {
    $method = strtolower($route['method']);
    if (!isset($openapi['paths'][$route['path']][$method])) $failures[] = "{$key}: missing OpenAPI operation";
    if (!isset($sdkOperations[$key])) $failures[] = "{$key}: missing SDK operation";
    foreach ($route['layers'] as $layer => $record) {
        $counts[$record['status']]++;
        if ($record['status'] !== 'PASS' && ($record['reason'] === null || $record['owner'] === null || $record['issue'] === null)) {
            $failures[] = "{$key}: {$layer} blocker is not actionable";
        }
        if ($record['status'] === 'BLOCKED_EXTERNAL' && ($record['prerequisite'] === null || $record['command'] === null)) $failures[] = "{$key}: {$layer} external blocker needs prerequisite and command";
        if ($record['status'] === 'PASS' && trim((string) ($record['evidence'] ?? '')) === '') {
            $failures[] = "{$key}: {$layer} PASS has no semantic evidence";
        }
        if ($record['status'] === 'PASS') {
            foreach (array_filter(array_map('trim', explode(';', (string) $record['evidence']))) as $evidence) {
                [$reference] = explode('::', $evidence, 2);
                if (!is_file($base . '/' . $reference)) $failures[] = "{$key}: {$layer} evidence file does not exist: {$reference}";
            }
        }
    }
}
$routeCount = count($matrix['routes']);
$openapiOperationCount = 0;
foreach (($openapi['paths'] ?? []) as $operations) $openapiOperationCount += count($operations);
if ($routeCount !== $openapiOperationCount) $failures[] = 'route/OpenAPI operation count differs';
if ($failures !== []) {
    fwrite(STDERR, "api-handoff: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}
$summary = ['routes' => $routeCount, 'layer_records' => array_sum($counts), 'counts' => $counts, 'readiness' => ($counts['BLOCKED'] + $counts['BLOCKED_EXTERNAL']) === 0 ? 'READY' : 'NOT_READY_BLOCKED'];
$summaryPath = $base . '/docs/api-handoff/latest-result.json';
file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo 'api-handoff: PASS structural/parity checks; ' . json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
