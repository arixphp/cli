<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\Event;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    #[Test]
    public function it_maps_every_convenience_method_to_its_expression(): void
    {
        $expressions = [
            'hourly' => '0 * * * *',
            'daily' => '0 0 * * *',
            'weekly' => '0 0 * * 0',
            'monthly' => '0 0 1 * *',
            'everyMinute' => '* * * * *',
            'everyFiveMinutes' => '*/5 * * * *',
            'everyTenMinutes' => '*/10 * * * *',
            'everyFifteenMinutes' => '*/15 * * * *',
            'everyThirtyMinutes' => '*/30 * * * *',
            'everyTwoHours' => '0 */2 * * *',
            'everyTwoDays' => '0 0 */2 * *',
            'everyWeekday' => '0 0 * * 1-5',
            'everyWeekend' => '0 0 * * 6,0',
            'mondays' => '0 0 * * 1',
            'fridays' => '0 0 * * 5',
        ];

        foreach ($expressions as $method => $expression) {
            $event = new Event('task', static fn (): null => null);

            $this->assertSame($expression, $event->{$method}()->expression(), $method);
        }
    }

    #[Test]
    public function it_sets_the_minute_and_hour_without_touching_the_other_fields(): void
    {
        $event = (new Event('task', static fn (): null => null))->everyWeekday()->at('09:30');

        $this->assertSame('30 9 * * 1-5', $event->expression());
    }

    #[Test]
    public function it_builds_daily_and_weekly_at_expressions(): void
    {
        $this->assertSame('30 3 * * *', (new Event('task', static fn (): null => null))->dailyAt('03:30')->expression());
        $this->assertSame('15 5 * * 0', (new Event('task', static fn (): null => null))->weeklyAt('05:15')->expression());
    }

    #[Test]
    public function it_rejects_an_invalid_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Event('task', static fn (): null => null))->at('25:00');
    }

    #[Test]
    public function it_resolves_the_occurrence_in_the_event_timezone_and_normalizes_it_to_utc(): void
    {
        $event = (new Event('task', static fn (): null => null))->daily()->at('03:00')->timezone('Europe/Madrid');
        $now = new DateTimeImmutable('2026-10-19 01:05:00', new DateTimeZone('UTC'));

        $this->assertSame('2026-10-19 01:00:00', $event->occurrence($now)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_accepts_an_explicit_expression(): void
    {
        $event = (new Event('task', static fn (): null => null))->cron('*/7 * * * *');

        $this->assertSame('*/7 * * * *', $event->expression());
    }
}
