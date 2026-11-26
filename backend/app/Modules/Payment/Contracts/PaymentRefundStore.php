<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

interface PaymentRefundStore
{
    public function refundedAmount(string $paymentId): int;
}
