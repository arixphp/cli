<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\ScheduleReport;
use Arix\Scheduling\TaskResult;
use Arix\Scheduling\TaskStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScheduleReportTest extends TestCase
{
    #[Test]
    public function it_counts_every_status(): void
    {
        $report = new ScheduleReport([
            new TaskResult('report:daily', TaskStatus::Completed, 0.123),
            new TaskResult('sync:stocks', TaskStatus::Failed, 1.5, 'Connection refused'),
            new TaskResult('cache:prune', TaskStatus::NotDue),
        ]);

        $this->assertSame([
            'completed' => 1,
            'failed' => 1,
            'not_due' => 1,
        ], $report->totals());
        $this->assertTrue($report->failed());
    }

    #[Test]
    public function it_lists_only_the_events_that_ran_or_failed(): void
    {
        $report = new ScheduleReport([
            new TaskResult('report:daily', TaskStatus::Completed, 0.123),
            new TaskResult('sync:stocks', TaskStatus::Failed, 1.5, 'Connection refused'),
            new TaskResult('cache:prune', TaskStatus::NotDue),
        ]);

        $this->assertSame([
            '  OK   report:daily        completed in 0.12s',
            '  FAIL sync:stocks         failed: Connection refused',
        ], $report->lines());
    }

    #[Test]
    public function it_summarises_the_run(): void
    {
        $report = new ScheduleReport([
            new TaskResult('report:daily', TaskStatus::Completed, 0.123),
            new TaskResult('sync:stocks', TaskStatus::Failed, 1.5, 'Connection refused'),
            new TaskResult('cache:prune', TaskStatus::NotDue),
        ]);

        $this->assertSame('3 events: 1 completed, 1 failed, 1 skipped', $report->summary());
    }

    #[Test]
    public function it_reports_an_empty_run(): void
    {
        $report = new ScheduleReport();

        $this->assertSame([], $report->lines());
        $this->assertSame('0 events', $report->summary());
        $this->assertFalse($report->failed());
    }
}
