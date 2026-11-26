<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$source = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
$public = json_decode((string) file_get_contents($base . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
if ($source !== $public) { fwrite(STDERR, "api-contract: public/openapi.json is not synchronized\n"); exit(1); }
$routes = (string) file_get_contents($base . '/routes/api.php');
preg_match_all('~\$app->router->(?:get|post|put|patch|delete)\(\'([^\']+)\'~', $routes, $matches);
$routePaths = array_fill_keys(array_unique($matches[1] ?? []), true);
$specPaths = array_fill_keys(array_keys($source['paths'] ?? []), true);
$missing = array_diff_key($routePaths, $specPaths);
if ($missing !== []) { fwrite(STDERR, "api-contract: undocumented routes\n" . implode("\n", array_keys($missing)) . "\n"); exit(1); }
if (!isset($source['x-compatibility'], $source['x-read-guarantees'], $source['x-scope-contract'])) { fwrite(STDERR, "api-contract: required compatibility metadata missing\n"); exit(1); }
$requiredResponses = ['400', '401', '403', '404', '409', '422', '429', '500'];
foreach (($source['paths'] ?? []) as $path => $operations) {
    foreach ($operations as $method => $operation) {
        if (!is_array($operation)) continue;
        foreach (['operationId', 'x-api-version', 'x-deprecation', 'x-request-schema', 'x-response-envelope', 'x-request-body', 'x-authentication', 'x-idempotency', 'x-stable-error-codes'] as $field) {
            if (!array_key_exists($field, $operation)) { fwrite(STDERR, "api-contract: {$method} {$path} missing {$field}\n"); exit(1); }
        }
        if (($operation['x-request-body'] ?? null) === 'unknown' && !str_contains($path, '/webhooks/')) { fwrite(STDERR, "api-contract: {$method} {$path} has an unmapped non-provider request body\n"); exit(1); }
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true) && !str_contains($path, '/webhooks/') && ($operation['x-request-body'] ?? null) === 'application/json' && !isset($operation['requestBody']['content']['application/json']['schema'])) { fwrite(STDERR, "api-contract: {$method} {$path} JSON body has no schema\n"); exit(1); }
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !str_contains($path, '/webhooks/') && ($operation['x-response-envelope'] ?? null) === '#/components/schemas/SuccessEnvelope') { fwrite(STDERR, "api-contract: {$method} {$path} has an opaque generic success response\n"); exit(1); }
        foreach ($requiredResponses as $status) {
            if (!isset($operation['responses'][$status])) { fwrite(STDERR, "api-contract: {$method} {$path} missing {$status} response\n"); exit(1); }
        }
        $schema = $operation['x-request-schema'];
        if (!isset($source['components']['schemas'][$schema])) { fwrite(STDERR, "api-contract: {$method} {$path} missing request schema {$schema}\n"); exit(1); }
    }
}
if (!is_file($base . '/generated/sirocommerce.ts')) { fwrite(STDERR, "api-contract: generated SDK missing\n"); exit(1); }
echo 'api-contract: PASS (' . count($specPaths) . " paths)\n";
