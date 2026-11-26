<?php

declare(strict_types=1);

/**
 * Bounded production-proof runner. It never claims a budget pass without a
 * live target and records unavailable server evidence as BLOCKED.
 *
 * Usage:
 *   php scripts/bench.php --base-url=http://localhost:8080 --dataset=10000 --samples=20
 * Optional IDs/tokens: CART_ID, ADMIN_TOKEN, WEBHOOK_BODY, WEBHOOK_SIGNATURE.
 * Profiles are smoke (5), standard (20), and soak (100) samples; artifacts
 * are written to perf/artifacts unless overridden.
 */

$budgets = [
    'catalog-browse' => ['path' => '/api/products?per_page=20', 'method' => 'GET', 'budget_ms' => 400],
    'search' => ['path' => '/api/v2/admin/search?q=shoe&type=catalog&per_page=20', 'method' => 'GET', 'budget_ms' => 500, 'admin' => true],
    'quote' => ['path' => '/api/v2/checkout/quote', 'method' => 'POST', 'body_env' => 'CART_ID', 'body' => static fn (string $id): array => ['cart_id' => $id], 'budget_ms' => 300],
    'admin-orders' => ['path' => '/api/admin/orders?per_page=20', 'method' => 'GET', 'budget_ms' => 500, 'admin' => true],
    'reporting-failures' => ['path' => '/api/v2/admin/reports/failures?per_page=20', 'method' => 'GET', 'budget_ms' => 500, 'admin' => true],
    'headless-products' => ['path' => '/api/headless/graphql', 'method' => 'POST', 'body' => static fn (): array => ['query' => '{ products(page: 1, perPage: 20) { nodes { id name slug } page perPage } }'], 'budget_ms' => 300],
    'webhook-burst' => ['path' => '/api/webhooks/' . (getenv('WEBHOOK_PROVIDER') ?: 'proof'), 'method' => 'POST', 'body_env' => 'WEBHOOK_BODY', 'raw_body' => true, 'signature' => true, 'budget_ms' => 300],
];

$profiles = ['smoke' => 5, 'standard' => 20, 'soak' => 100];
$options = ['base-url' => '', 'dataset' => '', 'profile' => 'standard', 'samples' => 20, 'artifact-dir' => dirname(__DIR__, 2) . '/perf/artifacts'];
foreach ($argv as $arg) {
    foreach (array_keys($options) as $key) {
        $prefix = '--' . $key . '=';
        if (str_starts_with($arg, $prefix)) $options[$key] = substr($arg, strlen($prefix));
    }
}
$options['samples'] = (int) $options['samples'];

$timestamp = gmdate('Ymd\THis\Z');
$artifactDir = (string) $options['artifact-dir'];
if (!is_dir($artifactDir)) mkdir($artifactDir, 0775, true);
$evidence = [
    'schema_version' => 2,
    'generated_at' => gmdate(DATE_ATOM),
    'dataset' => (string) $options['dataset'],
    'target' => (string) $options['base-url'],
    'bounded_samples' => $options['samples'],
    'profile' => (string) $options['profile'],
    'budgets_source' => 'docs/09_PERFORMANCE_BUDGETS.md',
    'preflight' => [],
    'evidence_contract' => [
        'latency' => 'p50_ms, p95_ms, p99_ms',
        'errors' => 'error_rate and HTTP status counts',
        'db_queries' => 'X-Query-Count and X-N-Plus-One response headers; absent evidence is NOT_PROVEN',
    ],
    'scenarios' => [],
];

if (!in_array((string) $options['dataset'], ['10000', '50000', '100000'], true)) {
    fwrite(STDERR, "dataset must be 10000, 50000, or 100000\n");
    exit(2);
}
if (!isset($profiles[(string) $options['profile']])) {
    fwrite(STDERR, "profile must be smoke, standard, or soak\n");
    exit(2);
}
if ($options['samples'] < 1 || $options['samples'] > $profiles[(string) $options['profile']]) {
    fwrite(STDERR, 'samples must be between 1 and ' . $profiles[(string) $options['profile']] . " for profile {$options['profile']}\n");
    exit(2);
}
if ($options['base-url'] === '') {
    $evidence['preflight'] = ['status' => 'BLOCKED', 'reason' => 'No --base-url; live evidence was not attempted.'];
    foreach (array_keys($budgets) as $name) $evidence['scenarios'][$name] = blocked('No --base-url; live evidence was not attempted.');
    $evidence['scenarios']['queue-outbox-drain'] = blocked('No bounded worker command was supplied; queue drain evidence requires a real worker and dataset.');
    writeArtifacts($artifactDir, $timestamp, $evidence);
    echo "BLOCKED no --base-url; artifacts recorded\n";
    exit(0);
}
if (filter_var((string) $options['base-url'], FILTER_VALIDATE_URL) === false || !in_array((string) parse_url((string) $options['base-url'], PHP_URL_SCHEME), ['http', 'https'], true) || parse_url((string) $options['base-url'], PHP_URL_USER) !== null) {
    fwrite(STDERR, "base-url must be an http(s) URL without embedded credentials\n");
    exit(2);
}
$evidence['preflight'] = ['status' => 'PASS', 'target_url' => rtrim((string) $options['base-url'], '/'), 'dataset_requested' => (string) $options['dataset']];
$GLOBALS['__proof_dataset'] = (string) $options['dataset'];

foreach ($budgets as $name => $scenario) $evidence['scenarios'][$name] = runScenario($name, $scenario, (string) $options['base-url'], $options['samples']);
$evidence['scenarios']['queue-outbox-drain'] = blocked('No worker command is part of the HTTP proof runner; run the bounded worker separately and attach its metrics.');
writeArtifacts($artifactDir, $timestamp, $evidence);

$failed = false;
foreach ($evidence['scenarios'] as $result) if ($result['status'] === 'FAIL') $failed = true;
exit($failed ? 1 : 0);

/** @param array<string, mixed> $scenario
 *  @return array<string, mixed> */
function runScenario(string $name, array $scenario, string $baseUrl, int $samples): array
{
    $body = null;
    if (isset($scenario['body_env'])) {
        $envName = valueString($scenario['body_env']);
        $value = getenv($envName);
        if (!is_string($value) || $value === '') return blocked('Missing ' . $envName . '.');
        $body = isset($scenario['raw_body']) && $scenario['raw_body'] ? $value : ['value' => $value];
        if (isset($scenario['body']) && is_callable($scenario['body'])) $body = $scenario['body']($value);
    } elseif (isset($scenario['body']) && is_callable($scenario['body'])) $body = $scenario['body']();
    if (($scenario['admin'] ?? false) && (!is_string(getenv('ADMIN_TOKEN')) || getenv('ADMIN_TOKEN') === '')) return blocked('Missing ADMIN_TOKEN for staff route.');
    if (($scenario['signature'] ?? false) && (!is_string(getenv('WEBHOOK_SIGNATURE')) || getenv('WEBHOOK_SIGNATURE') === '')) return blocked('Missing WEBHOOK_SIGNATURE for signed webhook proof.');

    $times = [];
    $statuses = [];
    $sizes = [];
    $queryCounts = [];
    $cacheHits = [];
    $datasetHeaders = [];
    $nPlusOneHeaders = [];
    $peakBefore = memory_get_peak_usage(true);
    for ($i = 0; $i < $samples; $i++) {
        $headers = ['Accept: application/json', 'User-Agent: siro-performance-proof/1'];
        if (is_string(getenv('ADMIN_TOKEN')) && getenv('ADMIN_TOKEN') !== '') $headers[] = 'Authorization: Bearer ' . getenv('ADMIN_TOKEN');
        if (($scenario['signature'] ?? false)) $headers[] = 'X-Webhook-Signature: ' . valueString(getenv('WEBHOOK_SIGNATURE'));
        $method = is_string($scenario['method'] ?? null) ? $scenario['method'] : 'GET';
        $request = ['http' => ['method' => $method, 'timeout' => 15, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)]];
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
            $request['http']['content'] = $payload;
            $request['http']['header'] .= "\r\nContent-Type: application/json";
        }
        $started = hrtime(true);
        $http_response_header = [];
        $path = valueString($scenario['path'] ?? '');
        $response = @file_get_contents(rtrim($baseUrl, '/') . $path, false, stream_context_create($request));
        $times[] = (hrtime(true) - $started) / 1_000_000;
        $headersSeen = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $status = 0;
        foreach ($headersSeen as $header) if (preg_match('/HTTP\/\S+\s+(\d{3})/', $header, $match) === 1) $status = (int) $match[1];
        $statuses[] = $status;
        $sizes[] = is_string($response) ? strlen($response) : 0;
        foreach ($headersSeen as $header) {
            if (preg_match('/^X-Query-Count:\s*(\d+)/i', $header, $match) === 1) $queryCounts[] = (int) $match[1];
            if (preg_match('/^X-Cache:\s*(HIT|MISS)/i', $header, $match) === 1) $cacheHits[] = strtoupper($match[1]);
            if (preg_match('/^X-Proof-Dataset:\s*(\d+)/i', $header, $match) === 1) $datasetHeaders[] = $match[1];
            if (preg_match('/^X-N-Plus-One:\s*(\S+)/i', $header, $match) === 1) $nPlusOneHeaders[] = strtolower($match[1]);
        }
    }
    sort($times);
    $p95 = percentile($times, .95);
    $p99 = percentile($times, .99);
    $reachable = count(array_filter($statuses, static fn (int $status): bool => $status > 0)) > 0;
    if (!$reachable) {
        echo 'BLOCKED ' . $name . " target unreachable\n";
        return blocked('Target was unreachable; no HTTP response headers were received.');
    }
    $statusOk = count(array_filter($statuses, static fn (int $status): bool => $status >= 200 && $status < 300)) === $samples;
    $budgetMs = is_int($scenario['budget_ms'] ?? null) ? $scenario['budget_ms'] : 0;
    $expectedDataset = (string) $GLOBALS['__proof_dataset'];
    $datasetProven = $datasetHeaders !== [] && count(array_unique($datasetHeaders)) === 1 && $datasetHeaders[0] === $expectedDataset;
    $queryProven = $queryCounts !== [];
    $nPlusOneProven = $nPlusOneHeaders !== [] && count(array_unique($nPlusOneHeaders)) === 1 && in_array($nPlusOneHeaders[0], ['0', 'false', 'no', 'none'], true);
    $nPlusOneDetected = in_array('1', $nPlusOneHeaders, true) || in_array('true', $nPlusOneHeaders, true) || in_array('yes', $nPlusOneHeaders, true);
    $status = !$statusOk || $p95 > $budgetMs || $nPlusOneDetected ? 'FAIL' : (($datasetProven && $queryProven && $nPlusOneProven) ? 'PASS' : 'NOT_PROVEN');
    $result = [
        'status' => $status,
        'samples' => $samples,
        'http_statuses' => array_count_values($statuses),
        'p95_ms' => round($p95, 2),
        'p99_ms' => round($p99, 2),
        'latency' => ['p50_ms' => round(percentile($times, .50), 2), 'p95_ms' => round($p95, 2), 'p99_ms' => round($p99, 2)],
        'errors' => ['error_rate' => round(($samples - count(array_filter($statuses, static fn (int $status): bool => $status >= 200 && $status < 300))) / $samples, 4), 'http_statuses' => array_count_values($statuses)],
        'max_response_bytes' => max($sizes ?: [0]),
        'client_peak_memory_delta_bytes' => max(0, memory_get_peak_usage(true) - $peakBefore),
        'budget_ms' => $budgetMs,
        'db_queries' => ['status' => $queryProven ? 'PROVEN' : 'NOT_PROVEN', 'count' => $queryCounts === [] ? null : ['min' => min($queryCounts), 'max' => max($queryCounts), 'samples' => count($queryCounts)], 'n_plus_one' => ['status' => $nPlusOneDetected ? 'FAIL' : ($nPlusOneProven ? 'PROVEN' : 'NOT_PROVEN'), 'detected' => $nPlusOneDetected ? true : ($nPlusOneProven ? false : null)]],
        'target_dataset' => ['status' => $datasetProven ? 'PROVEN' : 'NOT_PROVEN', 'declared' => $datasetHeaders === [] ? null : array_values(array_unique($datasetHeaders)), 'expected' => $expectedDataset],
        'cache' => $cacheHits === [] ? 'BLOCKED: target did not export X-Cache' : array_count_values($cacheHits),
    ];
    echo valueString($result['status']) . ' ' . $name . ' p95=' . valueString($result['p95_ms']) . 'ms p99=' . valueString($result['p99_ms']) . "ms\n";
    return $result;
}

/** @param array<int, float> $values */
function percentile(array $values, float $fraction): float
{
    if ($values === []) return 0.0;
    return (float) $values[min(count($values) - 1, (int) ceil(count($values) * $fraction) - 1)];
}

/** @return array<string, string> */
function blocked(string $reason): array { return ['status' => 'BLOCKED', 'reason' => $reason]; }

function valueString(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/** @param array<string, mixed> $evidence */
function writeArtifacts(string $dir, string $timestamp, array $evidence): void
{
    $json = $dir . '/backend-proof-' . $timestamp . '.json';
    file_put_contents($json, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $markdown = '# Backend Performance Proof\n\n' .
        '- Dataset: `' . valueString($evidence['dataset'] ?? '') . '`\n' .
        '- Generated: `' . valueString($evidence['generated_at'] ?? '') . '`\n' .
        '- Query evidence: `' . valueString($evidence['query_count_method'] ?? '') . '`\n\n' .
        '| Scenario | Status | p95 ms | p99 ms | Notes |\n|---|---:|---:|---:|---|\n';
    /** @var array<string, array<string, mixed>> $scenarios */
    $scenarios = $evidence['scenarios'];
    foreach ($scenarios as $name => $result) {
        $note = isset($result['reason']) ? valueString($result['reason']) : valueString($result['db_queries']['status'] ?? '') . '; ' . valueString($result['target_dataset']['status'] ?? '');
        $markdown .= '| ' . $name . ' | ' . valueString($result['status'] ?? '') . ' | ' . valueString($result['p95_ms'] ?? '-') . ' | ' . valueString($result['p99_ms'] ?? '-') . ' | ' . $note . " |\n";
    }
    file_put_contents($dir . '/backend-proof-' . $timestamp . '.md', $markdown);
    echo "Artifacts: {$json}\n";
}
