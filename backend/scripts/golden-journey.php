<?php

declare(strict_types=1);

/**
 * Disposable real-HTTP Golden Journey harness.
 *
 * The database, JWT secret, and PHP server are per-run resources. Secrets are
 * supplied only to the process environment and are deliberately excluded
 * from evidence.
 */

$base = dirname(__DIR__);
$root = dirname($base);
$mode = in_array('--self-test', $argv, true) ? 'self-test' : 'journey';
$port = (int) (getenv('GOLDEN_JOURNEY_PORT') ?: 18080);
$baseUrl = 'http://127.0.0.1:' . $port;
$runId = gmdate('Ymd\THis\Z') . '-p' . getmypid();
$evidenceDir = $base . '/storage/evidence/golden-journey/' . $runId;
$database = $base . '/storage/tests/golden-journey-' . getmypid() . '.sqlite';
$server = null;
$serverPipes = [];
$secret = bin2hex(random_bytes(32));
$results = [];
$status = 'PASS';
$blockedBy = [];
const GOLDEN_HTTP_TIMEOUT = 2.0;
const GOLDEN_SERVER_WAIT_ATTEMPTS = 5;

function record(array &$results, string $name, bool $passed, mixed $detail = null): void
{
    $results[] = ['name' => $name, 'status' => $passed ? 'PASS' : 'FAIL', 'detail' => $detail];
}

function failRun(array &$blockedBy, string $reason): void
{
    $blockedBy[] = $reason;
}

function response(string $baseUrl, string $method, string $path, array $body = [], array $headers = []): array
{
    $payload = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
    $headers = array_merge(['Accept: application/json', 'X-Correlation-Id: golden-journey'], $headers);
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => GOLDEN_HTTP_TIMEOUT,
        'header' => implode("\r\n", $headers),
        'content' => $payload ?? '',
    ]]);
    $raw = @file_get_contents($baseUrl . $path, false, $context);
    $line = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $line, $match);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $header) {
        if (str_contains(strtolower($header), 'correlation')) {
            $responseHeaders[] = $header;
        }
    }
    return ['status' => (int) ($match[1] ?? 0), 'json' => $json, 'headers' => $responseHeaders, 'raw' => $raw];
}

function waitForServer(string $baseUrl): bool
{
    for ($i = 0; $i < GOLDEN_SERVER_WAIT_ATTEMPTS; $i++) {
        $health = response($baseUrl, 'GET', '/health/live');
        if ($health['status'] === 200) {
            return true;
        }
        usleep(100000);
    }
    return false;
}

function assertEnvelope(array &$results, string $name, array $result, int $status, bool $requiresData = true, bool $requiresMeta = true): bool
{
    $json = $result['json'];
    $ok = $result['status'] === $status && is_array($json)
        && array_key_exists('success', $json) && array_key_exists('message', $json)
        && (!$requiresMeta || array_key_exists('meta', $json))
        && (!$requiresData || array_key_exists('data', $json));
    record($results, $name, $ok, [
        'http_status' => $result['status'],
        'envelope' => is_array($json),
        'requires_data' => $requiresData,
        'requires_meta' => $requiresMeta,
    ]);
    return $ok;
}

function stopPortListeners(int $port): void
{
    if (PHP_OS_FAMILY !== 'Windows') return;
    $lines = [];
    @exec('netstat -ano -p tcp', $lines);
    foreach ($lines as $line) {
        if (!str_contains($line, ':' . $port) || !str_contains($line, 'LISTENING')) continue;
        $parts = preg_split('/\s+/', trim($line));
        $pid = (int) ($parts[count($parts) - 1] ?? 0);
        if ($pid > 0) @exec('taskkill /PID ' . $pid . ' /T /F >NUL 2>&1');
    }
}

function stopServer(mixed &$server, array &$serverPipes, int $port): void
{
    if (is_resource($server)) {
        $status = proc_get_status($server);
        $pid = is_array($status) && isset($status['pid']) ? (int) $status['pid'] : 0;
        proc_terminate($server);
        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            @exec('taskkill /PID ' . $pid . ' /T /F 2>NUL');
        }
    }
    stopPortListeners($port);
    foreach ($serverPipes as $pipe) {
        if (is_resource($pipe)) fclose($pipe);
    }
    $serverPipes = [];
    proc_close($server);
    $server = null;
}

function removeFileWithRetry(string $path): bool
{
    if (!is_file($path)) return true;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        if (@unlink($path) && !is_file($path)) return true;
        usleep(100000);
    }
    return !is_file($path);
}

function seedDatabase(string $base, string $database): void
{
    require $base . '/vendor/autoload.php';
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=' . $database);
    putenv('JWT_SECRET=' . bin2hex(random_bytes(32)));
    \Siro\Core\Database::configure(['driver' => 'sqlite', 'database' => $database, 'host' => '127.0.0.1', 'port' => 0, 'username' => '', 'password' => '', 'charset' => 'utf8']);
    foreach (glob($base . '/database/migrations/*.php') ?: [] as $migrationFile) {
        $migration = require $migrationFile;
        if (is_object($migration) && method_exists($migration, 'up')) {
            $migration->up();
        }
    }
    $variant = (new \App\Repositories\CartRepository())->createVariant([
        'id' => 'golden-variant-1', 'product_id' => 'golden-product-1', 'sku' => 'GOLDEN-001', 'price_minor' => 15000,
    ]);
    (new \App\Repositories\InventoryRepository())->setOnHand((string) $variant['id'], 10, 'golden-journey');
}

try {
    if (!is_dir(dirname($database))) {
        mkdir(dirname($database), 0775, true);
    }
    if (!is_dir($evidenceDir)) {
        mkdir($evidenceDir, 0775, true);
    }
    if (is_file($database)) {
        unlink($database);
    }
    if (!is_file($base . '/vendor/autoload.php')) {
        failRun($blockedBy, 'backend/vendor/autoload.php is missing; run composer install in backend');
    } else {
        seedDatabase($base, $database);
        record($results, 'disposable SQLite migrations and fixture setup', is_file($database));
    }

    if ($blockedBy === []) {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }
        $env = array_merge($env, [
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => $baseUrl,
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
            'JWT_SECRET' => $secret, 'CORS_ALLOWED_ORIGINS' => 'http://127.0.0.1:3000',
            'PAYMENT_MODE' => 'sandbox', 'DHL_MODE' => 'sandbox', 'SEARCH_PROVIDER' => 'native',
        ]);
        $php = PHP_BINARY;
        $command = escapeshellarg($php) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($base . '/public') . ' ' . escapeshellarg($base . '/public/index.php');
        stopPortListeners($port);
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open($command, [1 => ['file', $nullDevice, 'a'], 2 => ['file', $nullDevice, 'a']], $serverPipes, $base, $env);
        if (!is_resource($server) || !waitForServer($baseUrl)) {
            failRun($blockedBy, 'PHP built-in API server did not become ready at ' . $baseUrl);
        } else {
            $live = response($baseUrl, 'GET', '/health/live');
            assertEnvelope($results, 'live health and API envelope', $live, 200);
            record($results, 'correlation response header', $live['headers'] !== [], $live['headers']);
            $version = response($baseUrl, 'GET', '/api/version');
            $versionEnvelopeOk = assertEnvelope($results, 'generated API contract metadata', $version, 200, true, false);
            $metadata = is_array($version['json']['data'] ?? null) ? $version['json']['data'] : [];
            $sdkPathOk = is_file($base . '/generated/sirocommerce.ts');
            $openApiPathOk = is_file($base . '/docs/openapi.json');
            $versionOk = $versionEnvelopeOk && (($metadata['client']['sdk'] ?? null) === 'typescript');
            record($results, 'OpenAPI and generated SDK artifacts present', $versionOk && $sdkPathOk && $openApiPathOk, [
                'sdk' => $metadata['client']['sdk'] ?? null,
                'sdk_file' => $sdkPathOk,
                'openapi_file' => $openApiPathOk,
            ]);

            if ($mode === 'self-test') {
                record($results, 'self-test does not require a purchase mutation', true);
            } else {
                $cart = response($baseUrl, 'POST', '/api/carts', ['currency' => 'VND']);
                $cartOk = assertEnvelope($results, 'create cart', $cart, 201);
                $cartId = $cart['json']['data']['id'] ?? '';
                $cartToken = $cart['json']['data']['access_token'] ?? '';
                if ($cartOk && is_string($cartId) && is_string($cartToken)) {
                    $line = response($baseUrl, 'POST', '/api/carts/' . rawurlencode($cartId) . '/lines', ['variant_id' => 'golden-variant-1', 'qty' => 2], ['X-Cart-Token: ' . $cartToken]);
                    assertEnvelope($results, 'add fixture line with cart scope', $line, 201);
                    $quote = response($baseUrl, 'GET', '/api/carts/' . rawurlencode($cartId) . '/quote', [], ['X-Cart-Token: ' . $cartToken]);
                    $quoteOk = assertEnvelope($results, 'purchase quote', $quote, 200) && (($quote['json']['data']['total_minor'] ?? null) === 30000);
                    record($results, 'quote total is deterministic', $quoteOk, $quote['json']['data']['total_minor'] ?? null);
                    $v2Quote = response($baseUrl, 'POST', '/api/v2/checkout/quote', ['cart_id' => $cartId]);
                    assertEnvelope($results, 'v2 purchase quote', $v2Quote, 200);
                    $missingKey = response($baseUrl, 'POST', '/api/checkout/place', ['cart_id' => $cartId], ['X-Cart-Token: ' . $cartToken]);
                    assertEnvelope($results, 'missing idempotency key rejected', $missingKey, 422, false);
                    $order = response($baseUrl, 'POST', '/api/checkout/place', ['cart_id' => $cartId, 'guest_email' => 'golden@example.test'], ['X-Cart-Token: ' . $cartToken, 'Idempotency-Key: golden-order-1']);
                    $orderOk = assertEnvelope($results, 'place order', $order, 201);
                    $orderId = $order['json']['data']['id'] ?? '';
                    $guestToken = $order['json']['data']['guest_access_token'] ?? '';
                    $guestTokenOk = $orderOk && is_string($guestToken) && preg_match('/\A[0-9a-f]{64}\z/', $guestToken) === 1;
                    record($results, 'guest order token is returned as 64-hex bearer secret', $guestTokenOk);
                    $again = response($baseUrl, 'POST', '/api/checkout/place', ['cart_id' => $cartId, 'guest_email' => 'golden@example.test'], ['X-Cart-Token: ' . $cartToken, 'Idempotency-Key: golden-order-1']);
                    $sameOrder = assertEnvelope($results, 'idempotent repeat returns order', $again, 201) && ($again['json']['data']['id'] ?? null) === $orderId;
                    record($results, 'idempotency preserves order identity', $sameOrder);
                    $scopeDenied = response($baseUrl, 'GET', '/api/orders/' . rawurlencode((string) $orderId));
                    assertEnvelope($results, 'order requires access scope', $scopeDenied, 403, false);
                    $wrongToken = response($baseUrl, 'GET', '/api/orders/' . rawurlencode((string) $orderId), [], ['X-Order-Token: ' . str_repeat('0', 64)]);
                    assertEnvelope($results, 'wrong guest order token is rejected', $wrongToken, 403, false);
                    $orderRead = response($baseUrl, 'GET', '/api/orders/' . rawurlencode((string) $orderId), [], ['X-Order-Token: ' . $guestToken]);
                    assertEnvelope($results, 'read order with guest scope', $orderRead, 200);
                    record($results, 'order fixture has one line and transition', is_array($orderRead['json']['data']['lines'] ?? null) && count($orderRead['json']['data']['lines']) === 1 && count($orderRead['json']['data']['transitions'] ?? []) === 1);
                } else {
                    failRun($blockedBy, 'cart fixture response did not contain id and access token');
                }
            }
            $unauthenticated = response($baseUrl, 'GET', '/api/auth/me');
            assertEnvelope($results, 'authentication required', $unauthenticated, 401, false);
        }
    }
} catch (Throwable $e) {
    failRun($blockedBy, get_class($e) . ': ' . $e->getMessage());
} finally {
    stopServer($server, $serverPipes, $port);
    try {
        \Siro\Core\Database::purgeAll();
        \Siro\Core\Schema::resetPdo();
    } catch (Throwable) {
        // Cleanup below still retries after the runtime releases its handles.
    }
    $cleanup = removeFileWithRetry($database);
    if ($mode === 'self-test') {
        record($results, 'disposable database cleanup', $cleanup);
        if (!$cleanup) failRun($blockedBy, 'temporary SQLite database could not be removed');
    }
    $status = $blockedBy !== [] ? 'BLOCKED_BY_ENVIRONMENT' : (count(array_filter($results, static fn (array $r): bool => $r['status'] === 'FAIL')) === 0 ? 'PASS' : 'FAIL');
    $manifest = ['status' => $status, 'mode' => $mode, 'base_url' => $baseUrl, 'run_id' => $runId, 'results' => $results, 'blocked_by' => $blockedBy, 'secret_recorded' => false, 'cleanup' => ['database_removed' => $cleanup]];
    file_put_contents($evidenceDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    $lines = ['# Golden Journey Evidence', '', '- Status: **' . $status . '**', '- Mode: `' . $mode . '`', '- Base URL: `' . $baseUrl . '`', '- JWT secret: generated in environment only; not recorded', '- Cleanup: SQLite database removed = `' . ($cleanup ? 'true' : 'false') . '`', '', '## Checks'];
    foreach ($results as $result) $lines[] = '- `' . $result['status'] . '` ' . $result['name'];
    if ($blockedBy !== []) { $lines[] = ''; $lines[] = '## BLOCKED_BY_ENVIRONMENT'; foreach ($blockedBy as $reason) $lines[] = '- ' . $reason; }
    file_put_contents($evidenceDir . '/evidence.md', implode(PHP_EOL, $lines) . PHP_EOL);
}

fwrite(STDOUT, $status . ' ' . $evidenceDir . PHP_EOL);
exit($status === 'PASS' ? 0 : 2);
