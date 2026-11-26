<?php
declare(strict_types=1);
namespace App\Services;

use Siro\Core\Env;

/**
 * Thin outbound client to Siro ERP Lite. Presentation-only: no DB fork, scope-aware.
 */
final class ErpLiteClient
{
    private string $baseUrl;
    private string $secret;
    public function __construct()
    {
        $this->baseUrl = rtrim((string) Env::get('ERP_LITE_URL', ''), '/');
        $this->secret = (string) Env::get('ERP_WEBHOOK_SECRET', Env::get('ERP_LITE_SECRET', ''));
    }
    public function isConfigured(): bool { return $this->baseUrl !== '' && $this->secret !== ''; }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function loyaltyEvaluate(array $body, string $idempotencyKey): array
    {
        return $this->post('/api/v2/loyalty/tiers/evaluate', $body, $idempotencyKey);
    }
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function subscriptionRenew(string $id, array $body, string $idempotencyKey): array
    {
        return $this->post('/api/v2/billing/subscriptions/' . urlencode($id) . '/renew', $body, $idempotencyKey);
    }
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function posPlace(array $body, string $idempotencyKey): array
    {
        return $this->post('/api/v2/pos/orders', $body, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    private function post(string $path, array $body, string $idempotencyKey): array
    {
        if (!$this->isConfigured()) return ['configured' => false, 'simulated' => true, 'path' => $path, 'body' => $body];
        $url = $this->baseUrl . $path;
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $json, $this->secret);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Siro-ERP: sha256=' . $sig,
                'Idempotency-Key: ' . $idempotencyKey,
                'X-Store-Id: ' . (Env::get('ERP_STORE_ID', 'default') ?: 'default'),
                'X-Channel-Id: ' . (Env::get('ERP_CHANNEL_ID', 'web') ?: 'web'),
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($resp) || $resp === '') return ['status' => $code, 'error' => 'ERP Lite unreachable', 'simulated' => true];
        $decoded = json_decode($resp, true);
        return is_array($decoded) ? $decoded : ['status' => $code, 'raw' => $resp];
    }

    public static function signature(string $json, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $json, $secret);
    }

    public static function verify(string $json, string $header, string $secret): bool
    {
        if ($header === '' || $secret === '') return false;
        $expected = self::signature($json, $secret);
        return hash_equals($expected, $header);
    }
}
