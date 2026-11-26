<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Arr;

/**
 * Built-in COD gateway (spec 03§2: COD core). No external side effects —
 * capture/refund are local state confirmations, always idempotent.
 */
final class CodPaymentGateway implements PaymentGatewayInterface
{
    /** @param array<string, mixed> $order @param array<string, mixed> $ctx @return array<string, mixed> */
    public function authorize(array $order, array $ctx): array
    {
        return ['state' => 'authorized', 'provider_ref' => 'cod-' . Arr::str($order, 'id', 'unknown')];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    public function capture(string $paymentId, array $ctx): array
    {
        return ['state' => 'captured', 'payment_id' => $paymentId];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    public function refund(string $paymentId, int $amountMinor, array $ctx): array
    {
        return ['state' => 'refunded', 'payment_id' => $paymentId, 'amount_minor' => $amountMinor];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    public function cancel(string $paymentId, array $ctx): array
    {
        return ['state' => 'cancelled', 'payment_id' => $paymentId];
    }

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return false;
    }
}
