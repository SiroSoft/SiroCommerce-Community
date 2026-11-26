<?php

declare(strict_types=1);

namespace Tests\Feature;

use Siro\Core\Schedule;
use Tests\TestCase;

final class ScheduledCommerceOperationsTest extends TestCase
{
    public function test_schedule_registers_the_native_non_overlapping_command(): void
    {
        $schedule = new Schedule();
        require dirname(__DIR__, 2) . '/routes/schedule.php';

        $property = new \ReflectionProperty($schedule, 'tasks');
        $property->setAccessible(true);
        $tasks = $property->getValue($schedule);

        self::assertCount(4, $tasks);
        self::assertSame('command', $tasks[0]->type);
        self::assertSame('commerce:scheduled --apply', $tasks[0]->task);
        self::assertSame('command', $tasks[1]->type);
        self::assertSame('smart:aggregate', $tasks[1]->task);
        self::assertSame('command', $tasks[2]->type);
        self::assertSame('metrics:persist', $tasks[2]->task);
        self::assertSame('command', $tasks[3]->type);
        self::assertSame('search:index-products', $tasks[3]->task);
    }

    public function test_scheduled_command_is_dry_run_by_default(): void
    {
        $command = new \App\Console\Commands\ScheduledCommerceOperationsCommand(dirname(__DIR__, 2));

        self::assertSame(0, $command->run(['--dry-run', '--limit=1']));
    }
}
