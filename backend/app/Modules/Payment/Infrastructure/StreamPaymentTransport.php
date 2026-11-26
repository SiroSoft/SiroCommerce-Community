<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Contracts\PaymentTransportResponse;
use Siro\Core\Env;
use Siro\Core\Http;

final class StreamPaymentTransport implements PaymentTransport
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
    {
        $timeout = self::integerEnv('PAYMENT_HTTP_TIMEOUT', 15, 1, 120);
        $retries = self::integerEnv('PAYMENT_HTTP_RETRIES', 2, 0, 4);
        $lastError = null;
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                Http::timeout($timeout);
                $response = match (strtoupper($method)) {
                    'POST' => Http::post($url, $body, $headers),
                    'PUT' => Http::put($url, $body, $headers),
                    'PATCH' => Http::patch($url, $body, $headers),
                    'DELETE' => Http::delete($url, $body, $headers),
                    default => Http::get($url, [], $headers),
                };
                $result = new PaymentTransportResponse($response->status(), $response->body(), $response->headers());
                if (!$this->retryable($result) || $attempt === $retries) return $result;
            } catch (\Throwable $error) {
                $lastError = $error;
                if ($attempt === $retries) throw new \RuntimeException('Payment provider transport failed; operation is unknown', 0, $error);
            }
            usleep((int) (100000 * (2 ** $attempt)));
        }
        throw new \RuntimeException($lastError?->getMessage() ?? 'Payment provider transport failed; operation is unknown', 0, $lastError);
    }

    private function retryable(PaymentTransportResponse $response): bool
    {
        return in_array($response->status, [408, 425, 429], true) || $response->status >= 500;
    }

    private static function integerEnv(string $name, int $default, int $min, int $max): int
    {
        $value = Env::get($name);
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
