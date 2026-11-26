<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

interface CurrencyConverter
{
    public function convert(int $amountMinor, string $fromCurrency, string $toCurrency): int;
}
