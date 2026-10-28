<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\Contracts\ScheduleLock;
use Arix\Scheduling\Kernel;
use Arix\Scheduling\Schedule;
use Arix\Scheduling\ScheduleLogger;
use Arix\Scheduling\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function file_get_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

class KernelTest extends TestCase
{
    private string|null $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            $log = $this->directory . DIRECTORY_SEPARATOR . 'cron.log';

            if (is_file($log)) {
                unlink($log);
            }

            if (is_dir($this->directory)) {
                rmdir($this->directory);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_runs_the_schedule_at_the_supplied_instant(): void
    {
        $ran = false;
        $schedule = new Schedule();
        $schedule->call('task', function () use (&$ran): void {
            $ran = true;
        })->everyMinute();
        $kernel = new Kernel($schedule, new InMemoryScheduleLock(), 3600);

        $report = $kernel->handle($this->now('03:05:00'));

        $this->assertTrue($ran);
        $this->assertSame(TaskStatus::Completed, $report->results()[0]->status);
    }

    #[Test]
    public function it_does_not_run_an_event_outside_the_grace_window_at_that_instant(): void
    {
        $ran = false;
        $schedule = new Schedule();
        $schedule->call('task', function () use (&$ran): void {
            $ran = true;
        })->daily()->at('00:00');
        $kernel = new Kernel($schedule, new InMemoryScheduleLock(), 0);

        $report = $kernel->handle($this->now('03:05:00'));

        $this->assertFalse($ran);
        $this->assertSame(TaskStatus::NotDue, $report->results()[0]->status);
    }

    #[Test]
    public function it_records_the_report_in_the_schedule_log(): void
    {
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-kernel-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
        $schedule = new Schedule();
        $schedule->call('task', static fn (): null => null)->everyMinute();
        $logger = new ScheduleLogger($this->directory . DIRECTORY_SEPARATOR . 'cron.log', $this->directory);
        $kernel = new Kernel($schedule, new InMemoryScheduleLock(), 3600, $logger);

        $kernel->handle($this->now('03:05:00'));

        $contents = file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'cron.log');

        $this->assertIsString($contents);
        $this->assertStringContainsString('OK   task', $contents);
        $this->assertStringContainsString('1 events: 1 completed', $contents);
    }

    #[Test]
    public function it_propagates_logging_failures(): void
    {
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-kernel-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
        $schedule = new Schedule();
        $schedule->call('task', static fn (): null => null)->everyMinute();
        $logger = new ScheduleLogger($this->directory . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR . 'cron.log', $this->directory);
        $kernel = new Kernel($schedule, new InMemoryScheduleLock(), 3600, $logger);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schedule log is outside its directory.');

        $kernel->handle($this->now('03:05:00'));
    }

    #[Test]
    public function it_propagates_infrastructure_failures(): void
    {
        $lock = new class() implements ScheduleLock
        {
            public function acquire(string $event, string $occurrence): string|null
            {
                throw new RuntimeException('lock unavailable');
            }

            public function complete(string $event, string $occurrence, string $token): void {}

            public function release(string $event, string $occurrence, string $token): void {}

            public function isCompleted(string $event, string $occurrence): bool
            {
                return false;
            }
        };
        $schedule = new Schedule();
        $schedule->call('task', static fn (): null => null)->everyMinute();
        $kernel = new Kernel($schedule, $lock, 3600);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lock unavailable');

        $kernel->handle($this->now('03:05:00'));
    }

    private function now(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-19 {$time}", new DateTimeZone('UTC'));
    }
}
