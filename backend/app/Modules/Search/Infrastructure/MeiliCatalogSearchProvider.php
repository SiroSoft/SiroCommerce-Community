<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use App\Modules\Search\Application\CatalogIndexSchema;
use App\Modules\Search\Contracts\CatalogSearchProvider;
use Siro\Core\Env;
use Siro\Core\HttpResponse;

/**
 * Storefront catalog search against Meilisearch. Any timeout, transport error,
 * non-2xx response or malformed body returns null so the repository can fall
 * back to native SQL search. The request closure mirrors HttpSearchTransport.
 */
final class MeiliCatalogSearchProvider implements CatalogSearchProvider
{
    /** @param \Closure(string, string, array<string, string>): HttpResponse|null $request */
    public function __construct(
        private readonly ?\Closure $request = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
        private readonly float $timeoutSeconds = 1.5,
    ) {
        // HttpResponse is declared in Http.php; load it so injected request
        // closures can return the same response type as HttpSearchTransport.
        class_exists(\Siro\Core\Http::class);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{ids: list<string>, total: int}|null
     */
    public function search(string $q, array $filters, int $limit, int $offset): ?array
    {
        try {
            $base = trim($this->baseUrl ?? (string) Env::get('SEARCH_URL', ''));
            $key = trim($this->apiKey ?? (string) Env::get('SEARCH_API_KEY', ''));
            if ($base === '' || $key === '') return null;
            if (filter_var($base, FILTER_VALIDATE_URL) === false) return null;
            if (!in_array(strtolower((string) parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)) return null;

            $body = (new CatalogSearchRequestBuilder())->build($q, $filters, $limit, $offset);
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $url = rtrim($base, '/') . '/indexes/' . rawurlencode(CatalogIndexSchema::INDEX) . '/search';
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ];
            $response = $this->request !== null
                ? ($this->request)($url, $json, $headers)
                : $this->send($url, $json, $headers);
            if (!$response instanceof HttpResponse) return null;
            if ($response->status() < 200 || $response->status() >= 300) return null;

            $decoded = json_decode($response->body(), true);
            if (!is_array($decoded)) return null;
            $hits = $decoded['hits'] ?? null;
            if (!is_array($hits)) return null;

            $ids = [];
            foreach ($hits as $hit) {
                if (!is_array($hit)) continue;
                $id = $hit['id'] ?? null;
                if (is_string($id) && $id !== '') $ids[] = $id;
                elseif (is_int($id)) $ids[] = (string) $id;
            }
            $total = $decoded['estimatedTotalHits'] ?? count($ids);
            return ['ids' => $ids, 'total' => is_numeric($total) ? max(0, (int) $total) : count($ids)];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, string> $headers */
    private function send(string $url, string $json, array $headers): HttpResponse
    {
        $timeoutMs = max(100, (int) round($this->timeoutSeconds * 1000));
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $handle = curl_init($url);
        if ($handle === false) throw new \RuntimeException('Unable to initialize catalog search request');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body)) throw new \RuntimeException('Catalog search request failed: ' . $error);
        return new HttpResponse($status, $body, '');
    }
}
