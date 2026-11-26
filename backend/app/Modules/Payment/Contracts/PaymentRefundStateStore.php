<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

interface PaymentRefundStateStore
{
    /** @return string The resulting payment state. */
    public function applyRefund(string $paymentId, int $amountMinor, ?string $providerRef = null): string;
}
