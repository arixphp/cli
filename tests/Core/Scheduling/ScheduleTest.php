<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\Schedule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    #[Test]
    public function it_registers_events_by_name(): void
    {
        $schedule = new Schedule();
        $event = $schedule->call('report:daily', static fn (): null => null);

        $this->assertTrue($schedule->has('report:daily'));
        $this->assertSame(['report:daily'], array_keys($schedule->events()));
        $this->assertSame($event, $schedule->events()['report:daily']);
    }

    #[Test]
    public function it_applies_the_default_timezone_to_new_events(): void
    {
        $schedule = new Schedule('Europe/Madrid');

        $this->assertSame('Europe/Madrid', $schedule->call('task', static fn (): null => null)->getTimezone());
    }

    #[Test]
    public function it_rejects_a_duplicate_event_name(): void
    {
        $schedule = new Schedule();
        $schedule->call('report:daily', static fn (): null => null);

        $this->expectException(InvalidArgumentException::class);

        $schedule->call('report:daily', static fn (): null => null);
    }
}
