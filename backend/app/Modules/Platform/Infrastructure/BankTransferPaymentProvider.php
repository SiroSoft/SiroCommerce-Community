<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Contracts\BankTransferGateway;
use App\Modules\Payment\Contracts\PaymentCapabilities;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Domain\PaymentOperation;

final class BankTransferPaymentProvider implements PaymentProvider, PaymentCapabilities, PaymentReconciliationProvider
{
    public function __construct(private readonly BankTransferGateway $gateway)
    {
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function execute(PaymentOperation $operation, array $context): array
    {
        $order = ['id' => $operation->paymentId];
        return match ($operation->type) {
            'authorize' => $this->gateway->authorize($order, $context),
            'capture' => $this->gateway->capture($operation->paymentId, $context),
            'refund' => $this->gateway->refund($operation->paymentId, $operation->amount->amountMinor, $context),
            'void' => $this->gateway->cancel($operation->paymentId, $context),
            default => ['state' => 'unknown', 'error' => 'Unsupported bank transfer operation'],
        };
    }

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return $this->gateway->verifyWebhook($rawBody, $headers);
    }

    /** @return array<int, string> */
    public function capabilities(): array { return ['authorize', 'capture', 'refund', 'void']; }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function reconcile(array $operation): array
    {
        return ['state' => 'unknown', 'error' => 'Bank transfer has no external provider reconciliation surface'];
    }
}
