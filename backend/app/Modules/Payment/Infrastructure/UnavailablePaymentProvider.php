<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentCapabilities;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Domain\PaymentOperation;

/** Fail-closed capability used when a live provider is not configured. */
final class UnavailablePaymentProvider implements PaymentProvider, PaymentCapabilities, PaymentReconciliationProvider
{
    public function __construct(private readonly string $provider, private readonly string $reason) {}

    public function execute(PaymentOperation $operation, array $context): array
    {
        return ['state' => 'failed', 'provider' => $this->provider, 'error_code' => 'provider.unavailable', 'error' => 'Payment provider is unavailable'];
    }

    /** @return array<int, string> */
    public function capabilities(): array { return []; }

    public function verifyWebhook(string $rawBody, array $headers): bool { return false; }

    public function reconcile(array $operation): array
    {
        return ['state' => 'unknown', 'provider' => $this->provider, 'error_code' => 'provider.unavailable', 'reconciliation_required' => true];
    }

    public function reason(): string { return $this->reason; }
}
