<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAddSameCurrency(): void
    {
        $sum = (new Money(1000))->add(new Money(2500));
        $this->assertSame(3500, $sum->minor);
    }

    public function testNoFloatArithmetic(): void
    {
        // 0.1 + 0.2 case must stay in integers: 10 + 20 = 30 minor
        $sum = Money::sumLines([10, 20]);
        $this->assertSame(30, $sum->minor);
    }

    public function testRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(-1);
    }

    public function testRejectsCurrencyMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(100))->add(new Money(100, 'USD'));
    }

    public function testRejectsSignedIntOverflowAndAdditionOverflow(): void
    {
        self::assertSame(Money::MAX_MINOR, (new Money(Money::MAX_MINOR))->minor);
        $this->expectException(\InvalidArgumentException::class);
        (new Money(Money::MAX_MINOR))->add(new Money(1));
    }

    public function testRejectsOverflowingLineSum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::sumLines([Money::MAX_MINOR, 1]);
    }
}
