<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

interface PaymentStore
{
    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    public function setState(string $id, string $state, ?string $providerRef = null): void;
    public function setCapturedAmount(string $id, int $capturedAmountMinor): void;
}
