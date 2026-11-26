<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use App\Modules\Search\Application\CatalogIndexSchema;
use Siro\Core\HttpResponse;

/** Minimal Meilisearch index-management client used by search:index-products. */
final class MeiliCatalogIndexClient
{
    /** @param \Closure(string, string, ?string, array<string, string>): HttpResponse|null $request */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly ?\Closure $request = null,
        private readonly int $timeoutMs = 30000,
    ) {
        // HttpResponse is declared in Http.php; load it before constructing one.
        class_exists(\Siro\Core\Http::class);
    }

    public function deleteIndex(): HttpResponse
    {
        return $this->request('DELETE', '/indexes/' . rawurlencode(CatalogIndexSchema::INDEX));
    }

    public function createIndex(): HttpResponse
    {
        return $this->request('POST', '/indexes', [
            'uid' => CatalogIndexSchema::INDEX,
            'primaryKey' => 'id',
        ]);
    }

    /** @param array<string, array<int, string>> $settings */
    public function updateSettings(array $settings): HttpResponse
    {
        return $this->request('PATCH', '/indexes/' . rawurlencode(CatalogIndexSchema::INDEX) . '/settings', $settings);
    }

    /** @param array<int, array<string, mixed>> $documents */
    public function addDocuments(array $documents): HttpResponse
    {
        return $this->request('POST', '/indexes/' . rawurlencode(CatalogIndexSchema::INDEX) . '/documents', $documents);
    }

    /** Block until an asynchronous Meilisearch task settles. */
    public function waitTask(int $taskUid, float $timeoutSeconds = 20.0): void
    {
        $deadline = microtime(true) + max(1.0, $timeoutSeconds);
        while (microtime(true) < $deadline) {
            $response = $this->request('GET', '/tasks/' . $taskUid, null, 5000);
            if ($response->status() >= 200 && $response->status() < 300) {
                $decoded = json_decode($response->body(), true);
                $status = is_array($decoded) && is_string($decoded['status'] ?? null) ? $decoded['status'] : '';
                if ($status === 'succeeded') return;
                if ($status === 'failed' || $status === 'canceled') {
                    $error = is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)
                        ? $decoded['error']['message']
                        : 'task failed';
                    throw new \RuntimeException('Meilisearch task failed: ' . $error);
                }
            }
            usleep(150000);
        }
        throw new \RuntimeException('Meilisearch task timed out');
    }

    /**
     * @param array<int|string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body = null, ?int $timeoutMs = null): HttpResponse
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $json = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
        if ($this->request !== null) return ($this->request)($method, $url, $json, $headers);

        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $handle = curl_init($url);
        if ($handle === false) throw new \RuntimeException('Unable to initialize Meilisearch request');
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => $timeoutMs ?? $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs ?? $this->timeoutMs, 5000),
        ];
        if ($json !== null) $options[CURLOPT_POSTFIELDS] = $json;
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($responseBody)) throw new \RuntimeException('Meilisearch request failed: ' . $error);
        return new HttpResponse($status, $responseBody, '');
    }
}
