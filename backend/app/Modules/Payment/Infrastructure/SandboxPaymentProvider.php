<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentCapabilities;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Domain\PaymentOperation;

/** Deterministic local adapter. It is never used for production mode. */
final class SandboxPaymentProvider implements PaymentProvider, PaymentCapabilities, PaymentReconciliationProvider
{
    public function __construct(private readonly string $provider) {}

    public function execute(PaymentOperation $operation, array $context): array
    {
        $reference = 'sandbox-' . substr(hash('sha256', $this->provider . ':' . $operation->idempotencyKey), 0, 24);
        $state = match ($operation->type) {
            'authorize' => 'authorized',
            'capture' => 'captured',
            'refund' => 'refunded',
            'void' => 'voided',
            default => 'unknown',
        };
        return [
            'state' => $state,
            'provider_status' => $state,
            'provider' => $this->provider,
            'provider_ref' => $reference,
            'payment_id' => $operation->paymentId,
            'amount_minor' => $operation->amount->amountMinor,
            'currency' => $operation->amount->currency,
            'sandbox' => true,
        ];
    }

    /** @return array<int, string> */
    public function capabilities(): array { return ['authorize', 'capture', 'refund', 'void', 'dispute']; }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $signature = '';
        foreach ($headers as $name => $value) if (strtolower($name) === 'x-payment-signature' && is_string($value)) $signature = trim($value);
        if ($signature === '') return false;
        $given = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $rawBody, 'sandbox:' . $this->provider);
        return strlen($given) === strlen($expected) && hash_equals($expected, $given);
    }

    public function reconcile(array $operation): array
    {
        return ['state' => 'unknown', 'provider' => $this->provider, 'error_code' => 'sandbox.reconciliation_unavailable', 'reconciliation_required' => true];
    }
}
