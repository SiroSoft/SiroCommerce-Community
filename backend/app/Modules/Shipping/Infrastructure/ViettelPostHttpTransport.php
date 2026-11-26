<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Infrastructure;

use App\Modules\Shipping\Contracts\ShippingTransport;
use Siro\Core\Env;
use Siro\Core\Http;

final class ViettelPostHttpTransport implements ShippingTransport
{
    private ?string $token = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
    ) {
        if (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($this->baseUrl, 'https://')) {
            throw new \RuntimeException('ViettelPost API URL must be an HTTPS endpoint');
        }
        if (trim($this->username) === '' || trim($this->password) === '') {
            throw new \RuntimeException('VIETTELPOST_USERNAME and VIETTELPOST_PASSWORD are required');
        }
    }

    public static function fromEnvironment(): self
    {
        $configured = trim((string) Env::get('VIETTELPOST_API_URL', ''));
        return new self(
            $configured !== '' ? $configured : 'https://partner.viettelpost.vn',
            trim((string) Env::get('VIETTELPOST_USERNAME', '')),
            trim((string) Env::get('VIETTELPOST_PASSWORD', '')),
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function request(string $operation, array $payload): array
    {
        $paths = [
            'rates' => '/v2/order/getPriceAll',
            'create_shipment' => '/v2/order/createOrder',
            'create_return_label' => '/v2/order/createOrder',
            'cancel' => '/v2/order/cancelOrder',
            'track' => '/v2/order/getOrderTracking',
        ];
        $path = $paths[$operation] ?? throw new \InvalidArgumentException('Unsupported ViettelPost operation');
        Http::timeout($this->integerEnv('VIETTELPOST_HTTP_TIMEOUT', 15, 1, 120));
        $response = Http::post(rtrim($this->baseUrl, '/') . $path, $payload, [
            'Content-Type' => 'application/json',
            'Token' => $this->token(),
        ]);
        if (!$response->ok()) {
            throw new \RuntimeException('ViettelPost request was rejected with HTTP ' . $response->status());
        }
        $decoded = $response->json();
        if ($decoded === []) {
            throw new \RuntimeException('ViettelPost returned an invalid response');
        }
        return $decoded;
    }

    private function token(): string
    {
        if ($this->token !== null && $this->token !== '') {
            return $this->token;
        }
        $response = Http::post(rtrim($this->baseUrl, '/') . '/v2/user/Login', [
            'USERNAME' => $this->username,
            'PASSWORD' => $this->password,
        ], ['Content-Type' => 'application/json']);
        if (!$response->ok()) {
            throw new \RuntimeException('ViettelPost authentication was rejected');
        }
        $decoded = $response->json();
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $token = is_string($data['token'] ?? null) ? trim($data['token']) : '';
        if ($token === '') {
            throw new \RuntimeException('ViettelPost authentication returned no token');
        }
        return $this->token = $token;
    }

    private function integerEnv(string $name, int $default, int $min, int $max): int
    {
        $value = Env::get($name);
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
