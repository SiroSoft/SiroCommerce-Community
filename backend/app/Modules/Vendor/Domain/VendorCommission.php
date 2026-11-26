<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Domain;

/**
 * Commission snapshot math. Money is integer minor units and basis points
 * are 0..10000, so the calculation is integer-only (round half up).
 */
final class VendorCommission
{
    public static function minor(int $grossMinor, int $commissionBps): int
    {
        if ($commissionBps <= 0 || $grossMinor <= 0) return 0;
        $bps = min($commissionBps, 10000);
        return intdiv($grossMinor * $bps + 5000, 10000);
    }
}
