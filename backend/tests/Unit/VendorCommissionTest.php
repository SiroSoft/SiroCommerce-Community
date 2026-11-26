<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Vendor\Domain\VendorCommission;
use PHPUnit\Framework\TestCase;

final class VendorCommissionTest extends TestCase
{
    public function testRoundsHalfUpInMinorUnits(): void
    {
        self::assertSame(84, VendorCommission::minor(251, 3333));
        self::assertSame(33, VendorCommission::minor(100, 3333));
        self::assertSame(125, VendorCommission::minor(2500, 500));
        self::assertSame(0, VendorCommission::minor(2500, 0));
        self::assertSame(2500, VendorCommission::minor(2500, 10000));
        self::assertSame(2500, VendorCommission::minor(2500, 12000));
    }
}
