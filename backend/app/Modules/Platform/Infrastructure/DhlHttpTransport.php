<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Shipping\Contracts\DhlTransport;
use Siro\Core\Env;
use Siro\Core\Http;
use App\Modules\Shared\Domain\ProviderCircuitBreaker;

/** Production DHL transport. Sandbox behavior is deliberately kept in DhlSandboxTransport. */
final class DhlHttpTransport implements DhlTransport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {
        if (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($this->baseUrl, 'https://') || $this->apiKey === '' || $this->apiSecret === '') {
            throw new \RuntimeException('DHL production credentials and HTTPS endpoint are required');
        }
        $this->circuit = new ProviderCircuitBreaker($this->integerEnv('DHL_CIRCUIT_FAILURE_THRESHOLD', 3, 1, 20), $this->integerEnv('DHL_CIRCUIT_COOLDOWN', 30, 1, 3600));
    }

    private ProviderCircuitBreaker $circuit;

    public function request(string $operation, array $payload): array
    {
        if (!$this->circuit->allows()) return ['state' => 'unknown', 'error_code' => 'provider.circuit_open', 'error_class' => 'circuit_open', 'retryable' => true, 'error' => 'DHL circuit is open; reconciliation required'];
        $paths = [
            'rate' => '/shipping/v4/rates',
            'create_shipment' => '/shipping/v4/shipments',
            'create_return_label' => '/shipping/v4/shipments/return',
            'cancel_shipment' => '/shipping/v4/shipments/cancel',
            'track' => '/tracking/v3/shipments',
        ];
        $path = $paths[$operation] ?? throw new \InvalidArgumentException('Unsupported DHL operation');
        $timeout = $this->integerEnv('DHL_HTTP_TIMEOUT', 15, 1, 120);
        $retries = $this->integerEnv('DHL_HTTP_RETRIES', 2, 0, 4);
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                Http::timeout($timeout);
                $response = Http::post(rtrim($this->baseUrl, '/') . $path, $payload, [
                    'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret),
                    'Content-Type' => 'application/json',
                    'Idempotency-Key' => is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : hash('sha256', json_encode($payload) ?: ''),
                ]);
                $retryable = in_array($response->status(), [408, 425, 429], true) || $response->status() >= 500;
                if ($retryable) {
                    $this->circuit->failure();
                    if ($attempt < $retries) { usleep((int) (100000 * (2 ** $attempt))); continue; }
                    return ['state' => 'unknown', 'error_code' => 'provider.unavailable', 'error_class' => 'transient', 'retryable' => true, 'error' => 'DHL response requires reconciliation'];
                }
                // A non-retryable response is definitive and must not be retried.
                if (!$response->ok()) throw new DhlProviderRejectedException($response->status());
                $decoded = $response->json();
                if ($decoded === []) throw new \RuntimeException('DHL provider returned an invalid response');
                $this->circuit->success();
                return $decoded;
            } catch (DhlProviderRejectedException $error) {
                throw new \RuntimeException($error->getMessage(), 0, $error);
            } catch (\Throwable $error) {
                $this->circuit->failure();
                if ($attempt === $retries) return ['state' => 'unknown', 'error_code' => 'provider.timeout_or_transport', 'error_class' => 'transport', 'retryable' => true, 'error' => 'DHL response requires reconciliation'];
            }
        }
        return ['state' => 'unknown', 'error_code' => 'provider.unavailable', 'error_class' => 'transient', 'retryable' => true, 'error' => 'DHL response requires reconciliation'];
    }

    private function integerEnv(string $name, int $default, int $min, int $max): int
    {
        $value = Env::get($name);
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}

final class DhlProviderRejectedException extends \RuntimeException
{
    public function __construct(int $status)
    {
        parent::__construct('DHL provider rejected operation: ' . $status);
    }
}
