<?php

declare(strict_types=1);

/**
 * Build the API handoff evidence index from the executable route declarations.
 *
 * This is deliberately not a test-reference counter. Every route/method gets
 * five independently reviewable evidence records. Missing semantic evidence is
 * BLOCKED and must carry a reason and owner in coverage-overrides.json.
 */
$base = dirname(__DIR__);
$routesSource = (string) file_get_contents($base . '/routes/api.php');
$overridesPath = $base . '/tests/api-handoff/coverage-overrides.json';
$overrides = is_file($overridesPath) ? json_decode((string) file_get_contents($overridesPath), true, 512, JSON_THROW_ON_ERROR) : [];

preg_match_all('~\$app->router->(get|post|put|patch|delete)\(\'([^\']+)\'~i', $routesSource, $matches, PREG_SET_ORDER);
$routes = [];
foreach ($matches as $match) {
    $method = strtoupper($match[1]);
    $path = $match[2];
    $key = $method . ' ' . $path;
    $override = is_array($overrides[$key] ?? null) ? $overrides[$key] : [];
    $localSafeReconciliation = $key === 'POST /api/admin/payments/reconcile';
    $providerDependent = !$localSafeReconciliation && (str_contains($path, '/webhooks/')
        || str_contains($path, '/shipping/')
        || str_contains($path, '/shipments')
        || str_contains($path, '/payments/'));
    $layers = [];
    foreach (['unit', 'integration', 'api_component', 'contract', 'workflow'] as $layer) {
        $record = is_array($override[$layer] ?? null) ? $override[$layer] : [];
        $status = $providerDependent ? 'BLOCKED_EXTERNAL' : ($record['status'] ?? 'BLOCKED');
        if (!in_array($status, ['PASS', 'BLOCKED', 'BLOCKED_EXTERNAL', 'SKIP'], true)) {
            throw new RuntimeException("{$key}: invalid {$layer} status");
        }
        $reason = $providerDependent
            ? 'Provider runtime certification is required; local boundary tests do not prove the live provider route.'
            : ($record['reason'] ?? ($status === 'PASS' ? null : 'No exact semantic evidence registered for this route/layer.'));
        if ($status !== 'PASS' && trim((string) $reason) === '') {
            throw new RuntimeException("{$key}: {$layer} needs an explicit reason");
        }
        $layers[$layer] = [
            'status' => $status,
            'evidence' => $providerDependent ? null : ($record['evidence'] ?? null),
            'reason' => $reason,
            'owner' => $providerDependent ? 'Provider Operations' : ($record['owner'] ?? ($status === 'PASS' ? null : 'API-1')),
            'issue' => $providerDependent ? 'PROVIDER-RUNTIME-CERTIFICATION' : ($record['issue'] ?? ($status === 'PASS' ? null : 'API-1-coverage-gap')),
            'prerequisite' => $providerDependent ? 'Provider credentials, HTTPS endpoint, and authenticated fixture' : ($record['prerequisite'] ?? null),
            'command' => $providerDependent ? 'php scripts/provider-live-smoke.php' : ($record['command'] ?? null),
        ];
    }
    $routes[$key] = ['method' => $method, 'path' => $path, 'layers' => $layers];
}
ksort($routes);
$document = [
    'schema' => 'sirocommerce.api-handoff.coverage.v1',
    'generated_from' => 'routes/api.php',
    'generated_at' => 'deterministic',
    'route_count' => count($routes),
    'coverage_policy' => 'PASS requires semantic evidence; BLOCKED/SKIP requires reason, owner, and issue; route references alone never qualify.',
    'routes' => $routes,
];
$output = $base . '/docs/api-handoff/coverage-matrix.json';
if (!is_dir(dirname($output))) mkdir(dirname($output), 0775, true);
file_put_contents($output, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo 'api-handoff: generated ' . count($routes) . " route records at {$output}\n";
