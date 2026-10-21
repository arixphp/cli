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
use RuntimeException;

use function is_dir;
use function is_file;
use function is_link;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function symlink;
use function unlink;

class ScheduleLoggerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-cron-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        $log = $this->directory . DIRECTORY_SEPARATOR . 'cron.log';

        if (is_file($log) || is_link($log)) {
            unlink($log);
        }

        if (is_dir($log)) {
            rmdir($log);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_appends_the_report_with_a_timestamp(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'cron.log';
        $logger = new ScheduleLogger($path, $this->directory);
        $report = new ScheduleReport([
            new TaskResult('report:daily', TaskStatus::Completed, 0.123),
            new TaskResult('cache:prune', TaskStatus::NotDue),
        ]);
        $now = new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'));

        $logger->write($report, $now);

        $contents = file_get_contents($path);

        $this->assertStringContainsString('[2026-10-19 03:00:00 UTC] OK   report:daily', $contents);
        $this->assertStringContainsString('2 events: 1 completed, 1 skipped', $contents);
        $this->assertStringNotContainsString('cache:prune', $contents);
    }

    #[Test]
    public function it_ignores_an_unwritable_log_file(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'cron.log';
        mkdir($path);
        $logger = new ScheduleLogger($path, $this->directory);
        $report = new ScheduleReport([new TaskResult('task', TaskStatus::Completed, 0.1)]);
        $now = new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'));

        set_error_handler(static fn (): bool => true);

        try {
            $logger->write($report, $now);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue(is_dir($path));
    }

    #[Test]
    public function it_refuses_a_log_outside_its_directory(): void
    {
        $logger = new ScheduleLogger($this->directory . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR . 'cron.log', $this->directory);
        $report = new ScheduleReport([new TaskResult('task', TaskStatus::Completed, 0.1)]);
        $now = new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'));

        $this->expectException(RuntimeException::class);
        $logger->write($report, $now);
    }

    #[Test]
    public function it_refuses_a_linked_log_file(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'target.log';
        file_put_contents($target, '');
        $link = $this->directory . DIRECTORY_SEPARATOR . 'cron.log';
        symlink($target, $link);

        $logger = new ScheduleLogger($link, $this->directory);
        $report = new ScheduleReport([new TaskResult('task', TaskStatus::Completed, 0.1)]);
        $now = new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'));

        try {
            $this->expectException(RuntimeException::class);
            $logger->write($report, $now);
        } finally {
            unlink($link);
            unlink($target);
        }
    }
}
