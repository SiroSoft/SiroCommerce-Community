<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Payment provider contract (spec docs/07). V1 must not hard-code providers.
 * All money in INT minor units. Webhook verify uses RAW body first.
 */
interface PaymentGatewayInterface
{
    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function authorize(array $order, array $ctx): array;

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function capture(string $paymentId, array $ctx): array;

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function refund(string $paymentId, int $amountMinor, array $ctx): array;

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function cancel(string $paymentId, array $ctx): array;

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool;
}
