<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\OrderTransition;
use PHPUnit\Framework\TestCase;

final class OrderTransitionTest extends TestCase
{
    public function testHappyPath(): void
    {
        $this->assertTrue(OrderTransition::can('pending', 'awaiting_payment'));
        $this->assertTrue(OrderTransition::can('awaiting_payment', 'paid'));
        $this->assertTrue(OrderTransition::can('paid', 'processing'));
        $this->assertTrue(OrderTransition::can('processing', 'ready_to_ship'));
        $this->assertTrue(OrderTransition::can('ready_to_ship', 'shipped'));
        $this->assertTrue(OrderTransition::can('shipped', 'delivered'));
    }

    public function testRejectsSkip(): void
    {
        $this->assertFalse(OrderTransition::can('pending', 'shipped'));
        $this->assertFalse(OrderTransition::can('delivered', 'paid'));
    }

    public function testAssertThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderTransition::assert('pending', 'shipped');
    }
}
