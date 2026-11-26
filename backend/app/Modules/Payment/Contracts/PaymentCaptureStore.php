<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

interface PaymentCaptureStore
{
    public function applyCapture(string $id, int $amountMinor, ?string $providerRef = null): void;
}
