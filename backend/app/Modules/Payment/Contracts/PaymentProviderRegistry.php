<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

use App\Modules\Payment\Domain\PaymentOperation;

interface PaymentProviderRegistry extends PaymentProvider
{
    public function provider(string $id): PaymentProvider;

    /** @return array<int, string> */
    public function providers(): array;
}
