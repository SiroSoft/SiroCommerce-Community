<?php

declare(strict_types=1);

namespace App\Modules\Launch\Application;

use App\Support\Mailer;
use Siro\Core\Env;

/**
 * Read-only provider probes for operators.
 *
 * Each configured provider is probed with a 5s timeout where the provider has
 * a safe read endpoint. Providers without a read API are reported as
 * configuration-only. Results contain only names, booleans, and short status
 * details — credential values never leave this service.
 */
final class ProviderProbeService
{
    public const TIMEOUT_MS = 5000;

    private const STRIPE_BALANCE_URL = 'https://api.stripe.com/v1/balance';

    private readonly ProviderStatusService $status;

    /** @var \Closure(string, string, array<string, string>, int): array{status?: int, error?: string} */
    private readonly ?\Closure $transport;

    /**
     * @param \Closure(string, string, array<string, string>, int): array{status?: int, error?: string} $transport
     */
    public function __construct(?ProviderStatusService $status = null, ?\Closure $transport = null)
    {
        $this->status = $status ?? new ProviderStatusService();
        $this->transport = $transport;
    }

    /**
     * @return array{
     *   providers: array<int, array{name: string, type: string, configured: bool, reachable: bool, detail: string}>,
     *   certified: bool
     * }
     */
    public function verify(): array
    {
        $providers = [
            $this->probeStripe(),
            $this->probeVnPay(),
            $this->probeMomo(),
            $this->probeGhn(),
            $this->probeViettelPost(),
            $this->probeDhl(),
            $this->probeMeili(),
            $this->probeMail(),
        ];

        $certified = true;
        foreach ($providers as $provider) {
            if ($provider['configured'] && !$provider['reachable']) {
                $certified = false;
            }
        }

        return ['providers' => $providers, 'certified' => $certified];
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeStripe(): array
    {
        $configured = $this->status->paymentConfigured('stripe');
        if (!$configured) {
            return $this->entry('stripe', 'payment', false, false, 'credentials missing');
        }
        $secret = $this->env('STRIPE_SECRET_KEY');
        if ($secret === '') {
            $secret = $this->env('PAYMENT_STRIPE_SECRET_KEY');
        }
        $result = $this->request('GET', self::STRIPE_BALANCE_URL, ['Authorization' => 'Bearer ' . $secret]);
        return $this->fromResult('stripe', 'payment', $result, 'balance');
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeVnPay(): array
    {
        $configured = $this->status->paymentConfigured('vnpay');
        return $this->entry('vnpay', 'payment', $configured, $configured, $configured ? 'configuration only (no read API)' : 'credentials missing');
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeMomo(): array
    {
        $configured = $this->status->paymentConfigured('momo');
        return $this->entry('momo', 'payment', $configured, $configured, $configured ? 'configuration only (no read API)' : 'credentials missing');
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeGhn(): array
    {
        $configured = $this->status->shippingConfigured('ghn');
        if (!$configured) {
            return $this->entry('ghn', 'shipping', false, false, 'credentials missing');
        }
        $mode = strtolower($this->env('GHN_ENV', 'sandbox'));
        $base = $this->env('GHN_API_URL');
        if ($base === '') {
            $base = $mode === 'production' ? 'https://online-gateway.ghn.vn' : 'https://dev-online-gateway.ghn.vn';
        }
        $url = rtrim($base, '/') . '/shiip/public-api/v2/shop/all';
        $result = $this->request('GET', $url, [
            'Token' => $this->env('GHN_TOKEN'),
            'ShopId' => $this->env('GHN_SHOP_ID'),
        ]);
        return $this->fromResult('ghn', 'shipping', $result, 'shop/all');
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeViettelPost(): array
    {
        $configured = $this->status->shippingConfigured('viettelpost');
        return $this->entry('viettelpost', 'shipping', $configured, $configured, $configured ? 'configuration only (no read API)' : 'credentials missing');
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeDhl(): array
    {
        $configured = $this->status->shippingConfigured('dhl');
        return $this->entry('dhl', 'shipping', $configured, $configured, $configured ? 'configuration only (no read API)' : 'credentials missing');
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeMeili(): array
    {
        $provider = $this->searchProvider();
        $configured = in_array($provider, ['meili', 'meilisearch'], true) && $this->status->searchConfigured($provider);
        if (!$configured) {
            return $this->entry('meili', 'search', false, false, 'credentials missing');
        }
        $base = rtrim($this->env('SEARCH_URL'), '/');
        $headers = ['Authorization' => 'Bearer ' . $this->env('SEARCH_API_KEY')];
        $health = $this->request('GET', $base . '/health', $headers);
        $healthFailure = $this->failureDetail($health, 'health');
        if ($healthFailure !== null) {
            return $this->entry('meili', 'search', true, false, $healthFailure);
        }
        $stats = $this->request('GET', $base . '/stats', $headers);
        $statsFailure = $this->failureDetail($stats, 'stats');
        if ($statsFailure !== null) {
            return $this->entry('meili', 'search', true, false, $statsFailure);
        }
        return $this->entry('meili', 'search', true, true, 'health HTTP ' . $this->statusOf($health) . ', stats HTTP ' . $this->statusOf($stats));
    }

    /** @return array{name: string, type: string, configured: bool, reachable: bool, detail: string} */
    private function probeMail(): array
    {
        $configured = Mailer::isConfigured();
        return $this->entry('mail', 'mail', $configured, $configured, $configured ? 'configuration only (no network probe)' : 'credentials missing');
    }

    /**
     * @param array{status: int, error: string|null} $result
     * @return array{name: string, type: string, configured: bool, reachable: bool, detail: string}
     */
    private function fromResult(string $name, string $type, array $result, string $label): array
    {
        $failure = $this->failureDetail($result, $label);
        if ($failure !== null) {
            return $this->entry($name, $type, true, false, $failure);
        }
        return $this->entry($name, $type, true, true, $label . ' HTTP ' . $this->statusOf($result));
    }

    /**
     * @param array{status: int, error: string|null} $result
     */
    private function failureDetail(array $result, string $label): ?string
    {
        if ($result['error'] !== null) {
            return $label . ' request failed';
        }
        $status = $result['status'];
        if ($status >= 200 && $status < 300) {
            return null;
        }
        return $label . ' HTTP ' . $status;
    }

    /** @param array{status: int, error: string|null} $result */
    private function statusOf(array $result): int
    {
        return $result['status'];
    }

    /**
     * @return array{name: string, type: string, configured: bool, reachable: bool, detail: string}
     */
    private function entry(string $name, string $type, bool $configured, bool $reachable, string $detail): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'configured' => $configured,
            'reachable' => $reachable,
            'detail' => $detail,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, error: string|null}
     */
    private function request(string $method, string $url, array $headers): array
    {
        try {
            if ($this->transport !== null) {
                $response = ($this->transport)($method, $url, $headers, self::TIMEOUT_MS);
                $status = $response['status'] ?? 0;
                $error = $response['error'] ?? null;
                return [
                    'status' => $status,
                    'error' => is_string($error) && $error !== '' ? $error : null,
                ];
            }
            return $this->curl($method, $url, $headers);
        } catch (\Throwable) {
            return ['status' => 0, 'error' => 'request failed'];
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, error: string|null}
     */
    private function curl(string $method, string $url, array $headers): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'error' => 'request failed'];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method !== '' ? $method : 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body)) {
            return ['status' => 0, 'error' => 'request failed'];
        }
        return ['status' => $status, 'error' => null];
    }

    private function env(string $key, string $default = ''): string
    {
        $value = Env::get($key, $default);
        return is_string($value) ? trim($value) : $default;
    }

    private function searchProvider(): string
    {
        $provider = $this->env('CATALOG_SEARCH_PROVIDER', 'native');
        return strtolower($provider === '' ? 'native' : $provider);
    }
}
