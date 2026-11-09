<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\Event;
use App\Domain\Event\Exception\NegativeReadyMargin;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class EventReadinessTest extends TestCase
{
    public function testAnEventIsReadyTheWorkspaceMarginBeforeItsFirstDay(): void
    {
        $event = self::event('2026-12-13 10:00', '2026-12-14 18:00');

        self::assertNull($event->readyDaysBefore());
        self::assertSame('2026-12-10 00:00 Europe/Paris', $event->readyBy(3)->format('Y-m-d H:i e'));
    }

    public function testItsOwnMarginWinsOverTheWorkspaceOne(): void
    {
        $event = self::event('2026-12-13', '2026-12-14');

        $event->planReadiness(5);

        self::assertSame(5, $event->readyDaysBefore());
        self::assertSame('2026-12-08', BusinessTime::day($event->readyBy(3)));
    }

    public function testAMarginOfZeroMeansReadyOnTheFirstDay(): void
    {
        $event = self::event('2026-12-13', '2026-12-14');

        $event->planReadiness(0);

        self::assertSame('2026-12-13', BusinessTime::day($event->readyBy(3)));
        self::assertSame('2026-12-13', BusinessTime::day(self::event('2026-12-13', '2026-12-14')->readyBy(0)));
    }

    public function testClearingItsMarginFallsBackToTheWorkspaceOne(): void
    {
        $event = self::event('2026-12-13', '2026-12-14');
        $event->planReadiness(5);

        $event->planReadiness(null);

        self::assertNull($event->readyDaysBefore());
        self::assertSame('2026-12-10', BusinessTime::day($event->readyBy(3)));
    }

    public function testTheReadyByDayStaysAParisMidnightAcrossTheSpringClockChange(): void
    {
        self::assertSame('2026-03-27 00:00 Europe/Paris', self::event('2026-03-30', '2026-03-31')->readyBy(3)->format('Y-m-d H:i e'));
    }

    public function testTheReadyByDayFollowsAReschedule(): void
    {
        $event = self::event('2026-12-13', '2026-12-14');

        $event->reschedule(DateRange::fromDates(BusinessTime::at('2027-01-10'), BusinessTime::at('2027-01-11')));

        self::assertSame('2027-01-07', BusinessTime::day($event->readyBy(3)));
    }

    public function testANegativeMarginIsRefused(): void
    {
        $this->expectException(NegativeReadyMargin::class);

        self::event('2026-12-13', '2026-12-14')->planReadiness(-1);
    }

    private static function event(string $start, string $end): Event
    {
        return Event::schedule(TestWorkspace::get(), 'Marché de Noël', 'Lyon', DateRange::fromDates(BusinessTime::at($start), BusinessTime::at($end)));
    }
}
