<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\ScheduleLogger;
use Arix\Scheduling\ScheduleReport;
use Arix\Scheduling\TaskResult;
use Arix\Scheduling\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScheduleLoggerTest extends TestCase
{
    #[Test]
    public function it_appends_the_report_with_a_timestamp(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-cron-' . bin2hex(random_bytes(8)) . '.log';
        $logger = new ScheduleLogger($path);
        $report = new ScheduleReport([
            new TaskResult('report:daily', TaskStatus::Completed, 0.123),
            new TaskResult('cache:prune', TaskStatus::NotDue),
        ]);
        $now = new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'));

        $logger->write($report, $now);

        $contents = file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('[2026-10-19 03:00:00 UTC] OK   report:daily', $contents);
        $this->assertStringContainsString('2 events: 1 completed, 1 skipped', $contents);
        $this->assertStringNotContainsString('cache:prune', $contents);
    }

    #[Test]
    public function it_ignores_an_unwritable_log_file(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-cron-' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR . 'cron.log';
        $logger = new ScheduleLogger($path);
        $report = new ScheduleReport([new TaskResult('task', TaskStatus::Completed, 0.1)]);
        $now = new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'));

        $logger->write($report, $now);

        $this->assertTrue(true);
    }
}
