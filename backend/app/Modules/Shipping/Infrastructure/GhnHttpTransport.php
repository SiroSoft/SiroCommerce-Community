<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Infrastructure;

use App\Modules\Shipping\Contracts\ShippingTransport;
use Siro\Core\Env;
use Siro\Core\Http;

final class GhnHttpTransport implements ShippingTransport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $shopId,
    ) {
        if (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($this->baseUrl, 'https://')) {
            throw new \RuntimeException('GHN API URL must be an HTTPS endpoint');
        }
        if (trim($this->token) === '' || trim($this->shopId) === '') {
            throw new \RuntimeException('GHN_TOKEN and GHN_SHOP_ID are required');
        }
    }

    public static function fromEnvironment(): self
    {
        $environment = strtolower(trim((string) Env::get('APP_ENV', 'local')));
        $mode = strtolower(trim((string) Env::get('GHN_ENV', 'sandbox')));
        if ($environment === 'production' && $mode !== 'production') {
            throw new \RuntimeException('Sandbox GHN transport is forbidden in production');
        }
        if (!in_array($mode, ['sandbox', 'production'], true)) {
            throw new \RuntimeException('GHN_ENV must be sandbox or production');
        }
        $defaultUrl = $mode === 'production' ? 'https://online-gateway.ghn.vn' : 'https://dev-online-gateway.ghn.vn';
        $configured = trim((string) Env::get('GHN_API_URL', ''));
        return new self(
            $configured !== '' ? $configured : $defaultUrl,
            trim((string) Env::get('GHN_TOKEN', '')),
            trim((string) Env::get('GHN_SHOP_ID', '')),
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function request(string $operation, array $payload): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Token' => $this->token,
            'ShopId' => $this->shopId,
        ];
        $paths = [
            'rates' => '/shiip/public-api/v2/shipping-order/fee',
            'create_shipment' => '/shiip/public-api/v2/shipping-order/create',
            'create_return_label' => '/shiip/public-api/v2/shipping-order/create',
            'cancel' => '/shiip/public-api/v2/switch-status/cancel',
            'track' => '/shiip/public-api/v2/shipping-order/detail',
        ];
        $path = $paths[$operation] ?? throw new \InvalidArgumentException('Unsupported GHN operation');
        $url = rtrim($this->baseUrl, '/') . $path;
        Http::timeout($this->integerEnv('GHN_HTTP_TIMEOUT', 15, 1, 120));
        if ($operation === 'track') {
            $orderCode = is_string($payload['order_code'] ?? null) ? $payload['order_code'] : '';
            $response = Http::get($url, ['order_code' => $orderCode], $headers);
        } else {
            $response = Http::post($url, $payload, $headers);
        }
        if (!$response->ok()) {
            throw new \RuntimeException('GHN request was rejected with HTTP ' . $response->status());
        }
        $decoded = $response->json();
        if ($decoded === []) {
            throw new \RuntimeException('GHN returned an invalid response');
        }
        return $decoded;
    }

    private function integerEnv(string $name, int $default, int $min, int $max): int
    {
        $value = Env::get($name);
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
