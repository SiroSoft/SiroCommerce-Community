<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

/** Optional capability declaration kept separate from the legacy provider port. */
interface PaymentCapabilities
{
    /** @return array<int, string> */
    public function capabilities(): array;
}
