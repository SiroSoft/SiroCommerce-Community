<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use InvalidArgumentException;

final class Currency
{
    /** @var array<string, int> */
    private const MINOR_UNITS = [
        'BHD' => 3, 'CLP' => 0, 'DJF' => 0, 'EUR' => 2, 'GBP' => 2,
        'HKD' => 2, 'INR' => 2, 'JPY' => 0, 'KRW' => 0, 'KWD' => 3,
        'MGA' => 2, 'MRO' => 1, 'MUR' => 2, 'OMR' => 3, 'PHP' => 2,
        'PKR' => 2, 'QAR' => 2, 'RUB' => 2, 'SAR' => 2, 'TND' => 3,
        'USD' => 2, 'VND' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
    ];

    public static function normalize(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!isset(self::MINOR_UNITS[$currency])) {
            throw new InvalidArgumentException('Unsupported currency: ' . $currency);
        }
        return $currency;
    }

    public static function minorUnit(string $currency): int
    {
        return self::MINOR_UNITS[self::normalize($currency)];
    }

    public static function assertAmount(string $currency, int $amountMinor): void
    {
        self::normalize($currency);
        if ($amountMinor < 0 || $amountMinor > Money::MAX_MINOR) throw new InvalidArgumentException('Minor amount must fit a signed INT');
    }
}
