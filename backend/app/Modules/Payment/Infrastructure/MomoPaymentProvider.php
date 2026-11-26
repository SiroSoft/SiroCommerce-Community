<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Domain\PaymentProviderConfig;

final class MomoPaymentProvider implements PaymentProvider
{
    public function __construct(
        private readonly PaymentProviderConfig $config,
        private readonly PaymentTransport $transport,
    ) {
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function execute(PaymentOperation $operation, array $context): array
    {
        if ($operation->type !== 'authorize') {
            return ['provider' => 'momo', 'provider_ref' => $operation->paymentId, 'provider_status' => 'unsupported', 'status' => 'failed', 'state' => 'failed', 'error_code' => 'provider.unsupported_operation', 'error' => 'MoMo supports payment initiation only'];
        }
        $partnerCode = $this->config->credential('partner_code');
        $accessKey = $this->config->credential('access_key');
        $secretKey = $this->config->credential('secret_key');
        $requestId = $operation->id;
        $orderId = $operation->paymentId;
        $fields = [
            'partnerCode' => $partnerCode,
            'accessKey' => $accessKey,
            'requestId' => $requestId,
            'amount' => (string) $operation->amount->amountMinor,
            'orderId' => $orderId,
            'orderInfo' => $this->contextString($context, 'order_info', 'Payment ' . $orderId),
            'redirectUrl' => $this->contextString($context, 'redirect_url', $this->contextString($context, 'return_url', '')),
            'ipnUrl' => $this->contextString($context, 'ipn_url', $this->contextString($context, 'webhook_url', '')),
            'requestType' => $this->contextString($context, 'request_type', 'captureWallet'),
            'extraData' => $this->contextString($context, 'extra_data', ''),
            'lang' => $this->contextString($context, 'locale', 'vi'),
        ];
        $fields['signature'] = hash_hmac('sha256', $this->signatureData($fields), $secretKey);
        $body = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return ['provider' => 'momo', 'provider_ref' => $orderId, 'provider_status' => 'invalid', 'status' => 'failed', 'state' => 'failed', 'error_code' => 'provider.invalid_request', 'error' => 'Payment request could not be encoded'];
        }
        try {
            $response = $this->transport->request('POST', $this->endpoint(), ['Content-Type' => 'application/json'], $body);
        } catch (\Throwable) {
            return ['provider' => 'momo', 'provider_ref' => $orderId, 'provider_status' => 'unknown', 'status' => 'unknown', 'state' => 'unknown', 'error_code' => 'provider.transport_error', 'error' => 'MoMo request could not be confirmed; reconciliation required'];
        }
        if (!$response->successful()) {
            $retryable = in_array($response->status, [408, 425, 429], true) || $response->status >= 500;
            return [
                'provider' => 'momo',
                'provider_ref' => $orderId,
                'provider_status' => (string) $response->status,
                'status' => $retryable ? 'unknown' : 'failed',
                'state' => $retryable ? 'unknown' : 'failed',
                'error_code' => $retryable ? 'provider.unknown' : 'provider.rejected',
                'error' => $retryable ? 'MoMo response requires reconciliation' : 'MoMo rejected the payment request',
            ];
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return ['provider' => 'momo', 'provider_ref' => $orderId, 'provider_status' => 'invalid', 'status' => 'unknown', 'state' => 'unknown', 'error_code' => 'provider.invalid_response', 'error' => 'MoMo response requires reconciliation'];
        }
        $resultCode = $decoded['resultCode'] ?? null;
        $providerRef = is_string($decoded['orderId'] ?? null) && trim($decoded['orderId']) !== '' ? trim($decoded['orderId']) : $orderId;
        if (!is_int($resultCode) && !(is_string($resultCode) && preg_match('/^\d+$/', $resultCode) === 1)) {
            return ['provider' => 'momo', 'provider_ref' => $providerRef, 'provider_status' => 'invalid', 'status' => 'unknown', 'state' => 'unknown', 'error_code' => 'provider.invalid_state', 'error' => 'MoMo response requires reconciliation'];
        }
        $success = (int) $resultCode === 0;
        $redirectUrl = '';
        foreach (['payUrl', 'deeplink', 'qrCodeUrl'] as $key) {
            if (is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '') {
                $redirectUrl = trim($decoded[$key]);
                break;
            }
        }
        $result = [
            'provider' => 'momo',
            'provider_ref' => $providerRef,
            'provider_status' => (string) $resultCode,
            'status' => $success ? 'pending' : 'failed',
            'state' => $success ? 'unknown' : 'failed',
        ];
        if ($redirectUrl !== '') $result['redirect_url'] = $redirectUrl;
        if (!$success) {
            $result['error_code'] = 'provider.rejected';
            $message = is_string($decoded['message'] ?? null) ? trim($decoded['message']) : '';
            $result['error'] = $message !== '' ? $message : 'MoMo rejected the payment request';
        }
        return $result;
    }

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $fields = $this->parse($rawBody);
        $signature = strtolower(trim($fields['signature'] ?? ''));
        if ($signature === '') {
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'x-momo-signature') {
                    $signature = strtolower(trim($value));
                    break;
                }
            }
        }
        if ($signature === '') return false;
        unset($fields['signature']);
        ksort($fields);
        $expected = hash_hmac('sha256', $this->signatureData($fields), $this->config->credential('secret_key'));
        return hash_equals($expected, $signature);
    }

    /** @param array<string, string> $fields */
    private function signatureData(array $fields): string
    {
        ksort($fields);
        $parts = [];
        foreach ($fields as $key => $value) $parts[] = $key . '=' . $value;
        return implode('&', $parts);
    }

    /** @return array<string, string> */
    private function parse(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $result = [];
            foreach ($decoded as $key => $value) {
                if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) $result[$key] = (string) $value;
            }
            return $result;
        }
        $parsed = [];
        parse_str($rawBody, $parsed);
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_string($value)) $result[$key] = $value;
        }
        return $result;
    }

    private function endpoint(): string
    {
        $base = rtrim($this->config->baseUrl, '/');
        if (str_ends_with($base, '/v2/gateway/api/create')) return $base;
        if (str_ends_with($base, '/v2/gateway/api')) return $base . '/create';
        return $base . '/v2/gateway/api/create';
    }

    /** @param array<string, mixed> $context */
    private function contextString(array $context, string $key, string $default): string
    {
        $value = $context[$key] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
