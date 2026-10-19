<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\Schedule;
use Arix\Scheduling\ScheduleRunner;
use Arix\Scheduling\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ScheduleRunnerTest extends TestCase
{
    #[Test]
    public function it_runs_an_event_whose_occurrence_is_inside_the_grace_window(): void
    {
        $ran = false;
        $schedule = new Schedule();
        $schedule->call('task', function () use (&$ran): void {
            $ran = true;
        })->everyMinute();
        $lock = new InMemoryScheduleLock();
        $now = new DateTimeImmutable('2026-10-19 03:05:30', new DateTimeZone('UTC'));
        $report = (new ScheduleRunner($schedule, $lock, 3600))->run($now);

        $this->assertTrue($ran);
        $this->assertSame(TaskStatus::Completed, $report->results()[0]->status);
    }

    #[Test]
    public function it_does_not_run_an_event_whose_occurrence_is_outside_the_grace_window(): void
    {
        $ran = false;
        $schedule = new Schedule();
        $schedule->call('task', function () use (&$ran): void {
            $ran = true;
        })->daily()->at('00:00');
        $lock = new InMemoryScheduleLock();
        $now = new DateTimeImmutable('2026-10-19 05:00:00', new DateTimeZone('UTC'));
        $report = (new ScheduleRunner($schedule, $lock, 3600))->run($now);

        $this->assertFalse($ran);
        $this->assertSame(TaskStatus::NotDue, $report->results()[0]->status);
    }

    #[Test]
    public function it_runs_a_late_event_inside_the_grace_window_and_records_the_scheduled_occurrence(): void
    {
        $schedule = new Schedule();
        $schedule->call('task', static fn (): null => null)->daily()->at('00:00');
        $lock = new InMemoryScheduleLock();
        $now = new DateTimeImmutable('2026-10-19 00:05:00', new DateTimeZone('UTC'));
        (new ScheduleRunner($schedule, $lock, 3600))->run($now);

        $this->assertTrue($lock->isCompleted('task', '202610190000'));
        $this->assertFalse($lock->isCompleted('task', '202610190005'));
    }

    #[Test]
    public function it_does_not_execute_the_same_occurrence_twice(): void
    {
        $runs = 0;
        $schedule = new Schedule();
        $schedule->call('task', function () use (&$runs): void {
            $runs++;
        })->everyMinute();
        $lock = new InMemoryScheduleLock();
        $now = new DateTimeImmutable('2026-10-19 03:05:00', new DateTimeZone('UTC'));
        $runner = new ScheduleRunner($schedule, $lock, 3600);

        $first = $runner->run($now);
        $second = $runner->run($now);

        $this->assertSame(1, $runs);
        $this->assertSame(TaskStatus::Completed, $first->results()[0]->status);
        $this->assertSame(TaskStatus::AlreadyCompleted, $second->results()[0]->status);
    }

    #[Test]
    public function it_reports_a_locked_event_when_another_process_holds_it(): void
    {
        $schedule = new Schedule();
        $schedule->call('task', static fn (): null => null)->everyMinute();
        $lock = new InMemoryScheduleLock();
        $lock->hold('task', '202610190305');
        $now = new DateTimeImmutable('2026-10-19 03:05:00', new DateTimeZone('UTC'));
        $report = (new ScheduleRunner($schedule, $lock, 3600))->run($now);

        $this->assertSame(TaskStatus::Locked, $report->results()[0]->status);
    }

    #[Test]
    public function it_releases_the_lock_when_the_task_throws_so_the_next_tick_retries_it(): void
    {
        $schedule = new Schedule();
        $schedule->call('task', static function (): void {
            throw new RuntimeException('boom');
        })->everyMinute();
        $lock = new InMemoryScheduleLock();
        $now = new DateTimeImmutable('2026-10-19 03:05:00', new DateTimeZone('UTC'));
        $report = (new ScheduleRunner($schedule, $lock, 3600))->run($now);

        $this->assertSame(TaskStatus::Failed, $report->results()[0]->status);
        $this->assertSame('boom', $report->results()[0]->message);
        $this->assertFalse($lock->isCompleted('task', '202610190305'));
        $this->assertNotNull($lock->acquire('task', '202610190305'));
    }

    #[Test]
    public function it_reports_an_impossible_expression_as_failed_and_continues_with_the_other_events(): void
    {
        $ran = false;
        $schedule = new Schedule();
        $schedule->call('broken', static fn (): null => null)->cron('0 0 30 2 *');
        $schedule->call('task', function () use (&$ran): void {
            $ran = true;
        })->everyMinute();
        $lock = new InMemoryScheduleLock();
        $now = new DateTimeImmutable('2026-10-19 03:05:00', new DateTimeZone('UTC'));
        $report = (new ScheduleRunner($schedule, $lock, 3600))->run($now);

        $this->assertTrue($ran);
        $this->assertSame(TaskStatus::Failed, $report->results()[0]->status);
        $this->assertSame(TaskStatus::Completed, $report->results()[1]->status);
    }
}
