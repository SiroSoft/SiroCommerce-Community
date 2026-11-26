<?php

declare(strict_types=1);

/** Generate the client-safe TypeScript surface from the generated OpenAPI document. */
$base = dirname(__DIR__);
$document = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
$operations = [];
foreach (($document['paths'] ?? []) as $path => $methods) {
    foreach ($methods as $method => $operation) {
        if (!is_array($operation) || !isset($operation['operationId'])) continue;
        $operations[] = [
            'id' => (string) $operation['operationId'],
            'method' => strtoupper((string) $method),
            'path' => (string) $path,
            'deprecated' => (bool) ($operation['deprecated'] ?? false),
            'requestType' => (($operation['x-request-body'] ?? '') === 'application/json' || ($operation['x-request-body'] ?? '') === 'multipart/form-data') ? (string) ($operation['x-request-schema'] ?? '') : null,
            ];
    }
}
usort($operations, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
$schemaType = static function (mixed $schema) use (&$schemaType): string {
    if (!is_array($schema)) return 'unknown';
    if (isset($schema['$ref']) && is_string($schema['$ref'])) return substr($schema['$ref'], strrpos($schema['$ref'], '/') + 1);
    if (($schema['type'] ?? '') === 'array') return 'Array<' . $schemaType($schema['items'] ?? []) . '>';
    return match ($schema['type'] ?? null) {
        'integer', 'number' => 'number', 'boolean' => 'boolean', 'string' => 'string', 'object' => 'Record<string, unknown>', default => 'unknown',
    };
};
$lines = [
    '/* eslint-disable */',
    '/** GENERATED FILE. Run `php scripts/generate-openapi.php && php scripts/generate-sdk.php`. */',
    'export type ApiVersion = "v1" | "v2";',
    'export type QueryValue = string | number | boolean | null | undefined;',
    'export interface ApiError { success: false; message: string; meta: { timestamp: string; error_code?: string; errors?: Record<string, string[]>; [key: string]: unknown }; }',
    'export interface ApiEnvelope<T> { success: true; message: string; data: T; meta: Record<string, unknown>; }',
    'export interface PaginatedApiEnvelope<T> { success: true; message: string; data: T[]; meta: PaginationMeta; }',
    'export interface PaginationMeta { page: number; per_page: number; total: number; last_page: number; timestamp?: string; }',
    'export interface ApiRequestOptions<TBody = unknown> { token?: string; headers?: Record<string, string>; query?: Record<string, QueryValue>; path?: Record<string, string | number>; body?: TBody; idempotencyKey?: string; signal?: AbortSignal; }',
    'export interface SiroCommerceClientOptions { baseUrl: string; fetch?: typeof fetch; defaultHeaders?: Record<string, string>; }',
];
foreach (($document['components']['schemas'] ?? []) as $name => $schema) {
    if (!is_string($name) || !is_array($schema) || ($schema['type'] ?? '') !== 'object' || !is_array($schema['properties'] ?? null)) continue;
    if ($name === 'PaginationMeta') continue;
    $required = is_array($schema['required'] ?? null) ? array_fill_keys($schema['required'], true) : [];
    $lines[] = 'export interface ' . $name . ' {';
    foreach ($schema['properties'] as $field => $definition) {
        if (is_string($field)) $lines[] = '  ' . $field . (isset($required[$field]) ? ': ' : '?: ') . $schemaType($definition) . ';';
    }
    $lines[] = '}';
}
$lines = array_merge($lines, [
    'export class SiroCommerceClient {',
    '  private readonly http: typeof fetch;',
    '  constructor(private readonly options: SiroCommerceClientOptions) { this.http = options.fetch ?? fetch; }',
    '  async request<T>(method: string, path: string, options: ApiRequestOptions = {}): Promise<T> {',
    '    Object.entries(options.path ?? {}).forEach(([k, v]) => { path = path.replace(`{${k}}`, encodeURIComponent(String(v))); }); const url = new URL(path, this.options.baseUrl); Object.entries(options.query ?? {}).forEach(([k, v]) => { if (v !== undefined && v !== null) url.searchParams.set(k, String(v)); });',
    '    const headers: Record<string, string> = { Accept: "application/json", ...this.options.defaultHeaders, ...options.headers }; if (options.token) headers.Authorization = `Bearer ${options.token}`; if (options.idempotencyKey) headers["Idempotency-Key"] = options.idempotencyKey; if (options.body !== undefined) headers["Content-Type"] = "application/json";',
    '    const response = await this.http(url, { method, headers, body: options.body === undefined ? undefined : JSON.stringify(options.body), signal: options.signal });',
    '    const payload = await response.json() as T; if (!response.ok) throw payload; return payload;',
     '  }',
]);
foreach ($operations as $operation) {
    $id = $operation['id'];
    $path = $operation['path'];
    $method = $operation['method'];
    $suffix = $operation['deprecated'] ? ' /** @deprecated */' : '';
    $responseType = 'ApiEnvelope<unknown>';
    $successSchema = $document['paths'][$path][strtolower($method)]['responses']['200']['content']['application/json']['schema'] ?? [];
    if (($successSchema['$ref'] ?? '') === '#/components/schemas/PaginatedEnvelope') {
        $responseType = 'PaginatedApiEnvelope<unknown>';
    } elseif (($successSchema['$ref'] ?? '') === '#/components/schemas/HeadlessSchemaEnvelope') {
        $responseType = 'ApiEnvelope<HeadlessSchemaEnvelope>';
    } elseif (($successSchema['$ref'] ?? '') === '#/components/schemas/AiSuggestionEnvelope') {
        $responseType = 'ApiEnvelope<AiSuggestionEnvelope>';
    } elseif (($successSchema['$ref'] ?? '') === '#/components/schemas/ReportingContractEnvelope') {
        $responseType = 'ApiEnvelope<ReportingContractEnvelope>';
    } elseif (is_string($successSchema['$ref'] ?? null) && preg_match('~/components/schemas/([A-Za-z0-9]+)Envelope$~', $successSchema['$ref'], $responseMatch) === 1) {
        $responseType = 'ApiEnvelope<' . $responseMatch[1] . '>';
    }
    $requestType = is_string($operation['requestType']) && $operation['requestType'] !== '' ? 'ApiRequestOptions<' . $operation['requestType'] . '>' : 'ApiRequestOptions';
    $lines[] = sprintf('  %s(options: %s = {}): Promise<%s> { return this.request<%s>(%s, %s, options); }%s', $id, $requestType, $responseType, $responseType, var_export($method, true), var_export($path, true), $suffix);
}
$lines[] = '}';
$output = $base . '/generated/sirocommerce.ts';
if (!is_dir(dirname($output))) mkdir(dirname($output), 0775, true);
file_put_contents($output, implode(PHP_EOL, $lines) . PHP_EOL);
echo 'sdk: generated ' . count($operations) . " operations at {$output}\n";
