<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Domain\PaymentProviderConfig;

final class VnPayPaymentProvider implements PaymentProvider
{
    public function __construct(
        private readonly PaymentProviderConfig $config,
    ) {
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function execute(PaymentOperation $operation, array $context): array
    {
        if ($operation->type !== 'authorize') {
            return ['provider' => 'vnpay', 'provider_ref' => $operation->paymentId, 'provider_status' => 'unsupported', 'status' => 'failed', 'state' => 'failed', 'error_code' => 'provider.unsupported_operation', 'error' => 'VNPay supports payment initiation only'];
        }
        $tmnCode = $this->config->credential('tmn_code');
        $secret = $this->config->credential('hash_secret');
        $txnRef = $operation->paymentId;
        $params = [
            'vnp_Version' => '2.1.0',
            'vnp_Command' => 'pay',
            'vnp_TmnCode' => $tmnCode,
            'vnp_Locale' => $this->contextString($context, 'locale', 'vn'),
            'vnp_CurrCode' => $operation->amount->currency,
            'vnp_TxnRef' => $txnRef,
            'vnp_OrderInfo' => $this->contextString($context, 'order_info', 'Payment ' . $txnRef),
            'vnp_OrderType' => $this->contextString($context, 'order_type', 'other'),
            'vnp_Amount' => $operation->amount->amountMinor * 100,
            'vnp_ReturnUrl' => $this->contextString($context, 'return_url', $this->contextString($context, 'redirect_url', '')),
            'vnp_IpAddr' => $this->contextString($context, 'ip_addr', '127.0.0.1'),
            'vnp_CreateDate' => $this->contextString($context, 'create_date', date('YmdHis')),
        ];
        $expireDate = $this->contextString($context, 'expire_date', '');
        if ($expireDate !== '') $params['vnp_ExpireDate'] = $expireDate;
        ksort($params);
        $params['vnp_SecureHash'] = hash_hmac('sha512', $this->hashData($params), $secret);
        $url = rtrim($this->config->baseUrl, '?') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        return [
            'provider' => 'vnpay',
            'provider_ref' => $txnRef,
            'provider_status' => 'pending',
            'status' => 'pending',
            'state' => 'unknown',
            'redirect_url' => $url,
        ];
    }

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $params = $this->parse($rawBody);
        $given = is_string($params['vnp_SecureHash'] ?? null) ? strtolower(trim($params['vnp_SecureHash'])) : '';
        if ($given === '') return false;
        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);
        ksort($params);
        $expected = hash_hmac('sha512', $this->hashData($params), $this->config->credential('hash_secret'));
        return hash_equals($expected, $given);
    }

    /** @param array<string, scalar> $params */
    private function hashData(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) $parts[] = urlencode($key) . '=' . urlencode((string) $value);
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

    /** @param array<string, mixed> $context */
    private function contextString(array $context, string $key, string $default): string
    {
        $value = $context[$key] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
