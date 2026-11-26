<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class SharedMoneySafetyTest extends TestCase
{
    public function testRejectsSignedIntOverflowAndNegativeResults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(Money::MAX_MINOR, 'VND'))->add(new Money(1, 'VND'));
    }

    public function testRejectsOverflowingMultiplication(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(100000, 'VND'))->multiply(30000);
    }

    public function testRejectsNegativeSubtraction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(1, 'VND'))->subtract(new Money(2, 'VND'));
    }
}
