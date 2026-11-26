<?php

declare(strict_types=1);

/** Run backend evidence groups independently with a hard per-command budget. */
$base = dirname(__DIR__);
$phpunit = $base . '/vendor/bin/phpunit';
$timeoutSeconds = 110;
$runId = gmdate('Ymd\THis\Z') . '-p' . getmypid();
$evidenceDir = $base . '/storage/evidence/test-groups/' . $runId;
$groups = ['unit', 'contract', 'feature', 'integration', 'concurrency', 'security'];
$requestedGroups = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('TEST_GROUPS') ?: '')))));
if ($requestedGroups !== []) {
    $groups = array_values(array_intersect($groups, $requestedGroups));
}
$results = [];

if (!is_file($phpunit)) {
    fwrite(STDERR, "BLOCKED: PHPUnit is missing at {$phpunit}\n");
    exit(2);
}
if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0775, true) && !is_dir($evidenceDir)) {
    fwrite(STDERR, "BLOCKED: evidence directory could not be created\n");
    exit(2);
}

function runBounded(array $command, string $cwd, string $outputFile, int $timeoutSeconds, array $env): array
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($process)) {
        return ['status' => 'BLOCKED', 'exit_code' => null, 'timed_out' => false, 'duration_ms' => 0, 'output' => 'proc_open failed'];
    }
    $started = microtime(true);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $timedOut = false;
    while (true) {
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        $state = proc_get_status($process);
        if (!$state['running']) {
            break;
        }
        if ((microtime(true) - $started) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            if (PHP_OS_FAMILY === 'Windows' && !empty($state['pid'])) {
                @exec('taskkill /PID ' . (int) $state['pid'] . ' /T /F 2>NUL');
            }
            break;
        }
        usleep(100000);
    }
    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) fclose($pipe);
    $exitCode = proc_close($process);
    file_put_contents($outputFile, $output);
    return [
        'status' => $timedOut ? 'BLOCKED' : ($exitCode === 0 ? 'PASS' : 'FAIL'),
        'exit_code' => $exitCode,
        'timed_out' => $timedOut,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'output_file' => basename($outputFile),
    ];
}

function fileBatches(string $directory, int $size): array
{
    $files = glob($directory . '/*Test.php') ?: [];
    sort($files);
    return array_chunk($files, $size);
}

function testNameBatches(string $phpunit, string $base, string $suite, int $size): array
{
    $lines = [];
    $status = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' --list-tests --testsuite ' . escapeshellarg($suite), $lines, $status);
    if ($status !== 0) return [];
    $names = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*-\s(.+)$/', $line, $match) === 1) {
            $name = trim($match[1]);
            // PHPUnit appends data-set values in quotes; the method prefix selects all of them.
            $names[] = preg_replace('/".*$/', '', $name) ?: $name;
        }
    }
    return array_chunk(array_values(array_unique($names)), $size);
}

$env = array_merge($_ENV, $_SERVER, [
    'APP_ENV' => 'testing',
    'PAYMENT_MODE' => 'sandbox',
    'DHL_MODE' => 'sandbox',
    'SEARCH_PROVIDER' => 'native',
    'PROVIDER_LIVE_SMOKE' => '0',
]);
$env = array_filter($env, static fn (mixed $value): bool => is_scalar($value));
$env = array_map(static fn (mixed $value): string => (string) $value, $env);

$batches = [
    'unit' => array_map(static fn (array $names): array => ['filter' => $names], testNameBatches($phpunit, $base, 'unit', 4)),
    'contract' => array_map(static fn (array $names): array => ['filter' => $names], testNameBatches($phpunit, $base, 'contract', 4)),
    'feature' => array_map(static fn (array $names): array => ['filter' => $names], testNameBatches($phpunit, $base, 'feature', 4)),
    'integration' => array_map(static fn (array $names): array => ['filter' => $names], testNameBatches($phpunit, $base, 'integration', 4)),
    'concurrency' => [
        [$base . '/tests/Integration/InventoryRaceTest.php', $base . '/tests/Integration/MySqlConcurrencyProofTest.php', $base . '/tests/Unit/MySqlConcurrencyProofSchemaTest.php', $base . '/tests/Unit/PaymentOperationRaceTest.php'],
    ],
    'security' => [
        [$base . '/tests/Feature/AuthTest.php', $base . '/tests/Integration/CustomerSecurityTest.php', $base . '/tests/Unit/SecurityResidualsTest.php'],
    ],
];

foreach ($groups as $group) {
    $groupResult = ['group' => $group, 'status' => 'PASS', 'tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'batches' => []];
    foreach ($batches[$group] as $index => $files) {
        $junit = $evidenceDir . '/' . $group . '-' . ($index + 1) . '.xml';
        $output = $evidenceDir . '/' . $group . '-' . ($index + 1) . '.log';
        if (isset($files['filter'])) {
            $pattern = '~(?:' . implode('|', array_map(static fn (string $name): string => preg_quote($name, '~'), $files['filter'])) . ')~';
            $command = [PHP_BINARY, $phpunit, '--testsuite', $group, '--filter', $pattern, '--no-coverage', '--log-junit', $junit];
        } else {
            $command = array_merge([PHP_BINARY, $phpunit, '--no-coverage', '--log-junit', $junit], $files);
        }
        $batch = runBounded($command, $base, $output, $timeoutSeconds, $env);
        $batch['batch'] = $index + 1;
        if (is_file($junit)) {
            $xml = @simplexml_load_file($junit);
            if ($xml !== false) {
                foreach (['tests', 'failures', 'errors', 'skipped'] as $metric) {
                    $value = (int) ($xml[$metric] ?? ($xml->testsuite[0][$metric] ?? 0));
                    $batch[$metric] = $value;
                    $groupResult[$metric] += $value;
                }
            }
        }
        if (($batch['tests'] ?? 0) === 0 && $batch['status'] === 'PASS') {
            $batch['status'] = 'FAIL';
            $batch['exit_code'] = 1;
        }
        $groupResult['batches'][] = $batch;
        if ($batch['status'] === 'BLOCKED') $groupResult['status'] = 'BLOCKED';
        elseif ($batch['status'] === 'FAIL' && $groupResult['status'] !== 'BLOCKED') $groupResult['status'] = 'FAIL';
    }
    $groupResult['duration_ms'] = array_sum(array_map(static fn (array $batch): int => (int) $batch['duration_ms'], $groupResult['batches']));
    $results[] = $groupResult;
    printf("%s %s tests=%d duration_ms=%d\n", $groupResult['status'], $group, $groupResult['tests'], $groupResult['duration_ms']);
}

$goldenOutput = $evidenceDir . '/golden-journey.log';
$golden = runBounded([PHP_BINARY, $base . '/scripts/golden-journey.php', '--self-test'], $base, $goldenOutput, $timeoutSeconds, $env);
$golden['group'] = 'golden-journey-self-test';
$results[] = $golden;
printf("%s golden-journey-self-test duration_ms=%d\n", $golden['status'], $golden['duration_ms']);

$blocked = array_values(array_map(static fn (array $r): string => (string) $r['group'], array_filter($results, static fn (array $r): bool => $r['status'] === 'BLOCKED')));
$failed = array_values(array_map(static fn (array $r): string => (string) $r['group'], array_filter($results, static fn (array $r): bool => $r['status'] === 'FAIL')));
$manifest = [
    'status' => $blocked !== [] ? 'BLOCKED' : ($failed !== [] ? 'FAIL' : 'PASS'),
    'run_id' => $runId,
    'timeout_seconds' => $timeoutSeconds,
    'provider_policy' => 'sandbox/native only; no live providers',
    'groups' => $results,
    'blocked_groups' => $blocked,
    'failed_groups' => $failed,
];
file_put_contents($evidenceDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
echo json_encode($manifest, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($manifest['status'] === 'PASS' ? 0 : 2);
