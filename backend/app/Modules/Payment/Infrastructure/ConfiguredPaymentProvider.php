<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentCapabilities;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Domain\PaymentProviderConfig;
use App\Support\ProviderEvidence;
use App\Modules\Shared\Domain\ProviderStatusMapper;

abstract class ConfiguredPaymentProvider implements PaymentProvider, PaymentCapabilities, PaymentReconciliationProvider
{
    public function __construct(
        protected readonly PaymentProviderConfig $config,
        protected readonly PaymentTransport $transport,
    ) {
    }

    /** @return array<int, string> */
    public function capabilities(): array
    {
        return ['authorize', 'capture', 'refund', 'void', 'dispute'];
    }

    final public function execute(PaymentOperation $operation, array $context): array
    {
        $payload = [
            'operation' => $operation->type,
            'payment_id' => $operation->paymentId,
            'amount_minor' => $operation->amount->amountMinor,
            'currency' => $operation->amount->currency,
            'idempotency_key' => $operation->idempotencyKey,
            'context' => $context,
        ];
        try {
            $response = $this->transport->request('POST', rtrim($this->config->baseUrl, '/') . '/payments/' . $operation->type, $this->headers($operation), $this->encode($payload));
        } catch (\Throwable $error) {
            return [
                'state' => 'unknown',
                'error_code' => $this->transportErrorCode($error),
                'error' => 'Payment provider request could not be confirmed; reconciliation required',
            ];
        }
        if (!$response->successful()) {
            if (in_array($response->status, [408, 425, 429], true) || $response->status >= 500) {
                return ['state' => 'unknown', 'error_code' => 'provider.unknown', 'error' => 'Payment provider response requires reconciliation'];
            }
            return ['state' => 'failed', 'error_code' => $this->httpErrorCode($response->status), 'error' => 'Payment provider rejected operation'];
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return ['state' => 'unknown', 'error_code' => 'provider.invalid_response', 'error' => 'Payment provider response requires reconciliation'];
        }
        $providerState = $this->providerState($decoded);
        $mappedState = ProviderStatusMapper::mapPayment($providerState);
        if ($mappedState === null) return ['state' => 'unknown', 'error_code' => 'provider.invalid_state', 'error' => 'Payment provider response requires reconciliation'];
        $decoded['provider_status'] = $providerState;
        $decoded['state'] = $mappedState;
        $decoded['provider'] = $this->config->provider;
        $decoded['operation'] = $operation->type;
        $decoded['idempotency_key'] = $operation->idempotencyKey;
        if (array_key_exists('amount_minor', $decoded) && (int) $decoded['amount_minor'] !== $operation->amount->amountMinor) {
            return ['state' => 'unknown', 'error_code' => 'provider.amount_mismatch', 'error' => 'Payment provider response requires reconciliation'];
        }
        if (array_key_exists('currency', $decoded) && is_string($decoded['currency']) && strtoupper($decoded['currency']) !== $operation->amount->currency) {
            return ['state' => 'unknown', 'error_code' => 'provider.currency_mismatch', 'error' => 'Payment provider response requires reconciliation'];
        }
        /** @var array<string, mixed> $decoded */
        /** @var array<string, mixed> $safeResponse */
        $safeResponse = ProviderEvidence::redact($decoded);
        if (!isset($safeResponse['provider_ref'])) {
            foreach (['provider_payment_id', 'payment_id', 'transaction_id', 'id', 'reference'] as $reference) {
                if (is_string($safeResponse[$reference] ?? null) && trim($safeResponse[$reference]) !== '') {
                    $safeResponse['provider_ref'] = $safeResponse[$reference];
                    break;
                }
            }
        }
        return $safeResponse;
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $signature = '';
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-payment-signature' && is_string($value)) {
                $signature = trim($value);
                break;
            }
        }
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->config->credential('webhook_secret'));
        $given = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        return strlen($given) === strlen($expected) && hash_equals($expected, $given);
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    public function reconcile(array $operation): array
    {
        $reference = $operation['provider_ref'] ?? $operation['provider_payment_id'] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            return ['state' => 'manual_review', 'error_code' => 'provider.reference_missing'];
        }
        try {
            $response = $this->transport->request('GET', rtrim($this->config->baseUrl, '/') . '/payments/' . rawurlencode($reference), [
                'Authorization' => 'Bearer ' . $this->reconciliationCredential(),
                'Accept' => 'application/json',
            ], '');
        } catch (\Throwable $error) {
            return ['state' => 'unknown', 'error_code' => $this->transportErrorCode($error), 'reconciliation_required' => true];
        }
        if (!$response->successful()) return ['state' => 'unknown', 'error_code' => $this->httpErrorCode($response->status), 'reconciliation_required' => true];
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) return ['state' => 'unknown', 'error_code' => 'provider.invalid_response'];
        $providerState = $this->providerState($decoded);
        $mappedState = ProviderStatusMapper::mapPayment($providerState);
        if ($mappedState === null) return ['state' => 'unknown', 'error_code' => 'provider.invalid_state'];
        $decoded['provider_status'] = $providerState;
        $decoded['state'] = $mappedState;
        $decoded['provider'] = $this->config->provider;
        $decoded['reconciled_at'] = date(DATE_ATOM);
        /** @var array<string, mixed> $decoded */
        $safe = ProviderEvidence::redact($decoded);
        $result = [];
        foreach ($safe as $key => $value) if (is_string($key)) $result[$key] = $value;
        $expectedAmount = $operation['amount_minor'] ?? null;
        if (is_numeric($expectedAmount) && is_numeric($result['amount_minor'] ?? null) && (int) $expectedAmount !== (int) $result['amount_minor']) {
            $result['state'] = 'mismatch';
            $result['error_code'] = 'provider.amount_mismatch';
            return $result;
        }
        $rawCurrency = $operation['currency'] ?? '';
        $expectedCurrency = is_scalar($rawCurrency) ? strtoupper((string) $rawCurrency) : '';
        if ($expectedCurrency !== '' && is_string($result['currency'] ?? null) && strtoupper($result['currency']) !== $expectedCurrency) {
            $result['state'] = 'mismatch';
            $result['error_code'] = 'provider.currency_mismatch';
            return $result;
        }
        return $result;
    }

    /** @param array<mixed, mixed> $response */
    private function providerState(array $response): string
    {
        foreach (['state', 'status', 'payment_status', 'paymentStatus', 'resultCode'] as $key) {
            if (is_string($response[$key] ?? null)) return $response[$key];
        }
        foreach (['payment', 'data', 'transactionResponse'] as $key) {
            if (is_array($response[$key] ?? null)) {
                $nested = $this->providerState($response[$key]);
                if ($nested !== '') return $nested;
            }
        }
        return '';
    }

    private function transportErrorCode(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'timeout') || str_contains($message, 'timed out') ? 'provider.timeout' : 'provider.transport_error';
    }

    private function httpErrorCode(int $status): string
    {
        if (in_array($status, [408, 425, 429], true) || $status >= 500) return 'provider.unknown';
        if ($status === 401 || $status === 403) return 'provider.authentication_failed';
        return 'provider.rejected';
    }

    private function reconciliationCredential(): string
    {
        foreach (['secret_key', 'access_token', 'api_key'] as $name) {
            if (isset($this->config->credentials[$name]) && $this->config->credentials[$name] !== '') return $this->config->credentials[$name];
        }
        throw new \RuntimeException('Payment provider credentials are not configured');
    }

    /** @return array<string, string> */
    abstract protected function headers(PaymentOperation $operation): array;

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $body;
    }

}
