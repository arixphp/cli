<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\ConsoleReporter;
use Arix\Scheduling\ScheduleReport;
use Arix\Scheduling\TaskResult;
use Arix\Scheduling\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConsoleReporterTest extends TestCase
{
    #[Test]
    public function it_renders_the_header_results_and_summary(): void
    {
        $report = new ScheduleReport([
            new TaskResult('report:daily', TaskStatus::Completed, 0.123),
            new TaskResult('cache:prune', TaskStatus::NotDue),
        ]);
        $now = new DateTimeImmutable('2026-10-19 03:00:00', new DateTimeZone('UTC'));

        $output = (new ConsoleReporter())->render($report, $now);

        $this->assertSame(
            'Arix scheduler  2026-10-19 03:00:00 UTC' . PHP_EOL
            . '  OK   report:daily        completed in 0.12s' . PHP_EOL
            . '2 events: 1 completed, 1 skipped' . PHP_EOL,
            $output,
        );
    }

    #[Test]
    public function it_lists_only_the_failed_events_for_standard_error(): void
    {
        $report = new ScheduleReport([
            new TaskResult('report:daily', TaskStatus::Completed, 0.123),
            new TaskResult('sync:stocks', TaskStatus::Failed, 1.5, 'Connection refused'),
        ]);

        $this->assertSame([
            '  FAIL sync:stocks         failed: Connection refused',
        ], (new ConsoleReporter())->failures($report));
    }
}
