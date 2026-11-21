<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\DateRange;
use App\Domain\Shared\Exception\InvalidDateRange;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    public function testEndCannotPrecedeStart(): void
    {
        $this->expectException(InvalidDateRange::class);

        DateRange::fromDates(new \DateTimeImmutable('2026-05-10'), new \DateTimeImmutable('2026-05-09'));
    }

    public function testSingleDayRangeIsAllowed(): void
    {
        $range = DateRange::fromDates(new \DateTimeImmutable('2026-05-10'), new \DateTimeImmutable('2026-05-10'));

        self::assertTrue($range->covers(new \DateTimeImmutable('2026-05-10 23:59:59', new \DateTimeZone('Europe/Paris'))));
    }

    public function testYearCoversItsParisCalendarDays(): void
    {
        $range = DateRange::year(2026);
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertSame(365, $range->days());
        self::assertTrue($range->covers(new \DateTimeImmutable('2026-01-01 00:00:00', $paris)));
        self::assertTrue($range->covers(new \DateTimeImmutable('2026-12-31 23:59:59', $paris)));
        self::assertFalse($range->covers(new \DateTimeImmutable('2025-12-31 23:59:59', $paris)));
        self::assertFalse($range->covers(new \DateTimeImmutable('2027-01-01 00:00:00', $paris)));
    }

    public function testCoversWholeDaysInclusively(): void
    {
        $range = DateRange::fromDates(new \DateTimeImmutable('2026-05-09'), new \DateTimeImmutable('2026-05-10'));
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertTrue($range->covers(new \DateTimeImmutable('2026-05-09 00:00:00', $paris)));
        self::assertTrue($range->covers(new \DateTimeImmutable('2026-05-10 23:59:59', $paris)));
        self::assertFalse($range->covers(new \DateTimeImmutable('2026-05-08 23:59:59', $paris)));
        self::assertFalse($range->covers(new \DateTimeImmutable('2026-05-11 00:00:00', $paris)));
    }

    public function testCoverageUsesParisLocalDate(): void
    {
        $range = DateRange::fromDates(new \DateTimeImmutable('2026-05-10'), new \DateTimeImmutable('2026-05-10'));

        self::assertFalse($range->covers(new \DateTimeImmutable('2026-05-10T22:30:00+00:00')));
        self::assertTrue($range->covers(new \DateTimeImmutable('2026-05-09T22:30:00+00:00')));
    }

    public function testCountsDaysInclusively(): void
    {
        self::assertSame(1, DateRange::fromDates(new \DateTimeImmutable('2026-05-10'), new \DateTimeImmutable('2026-05-10'))->days());
        self::assertSame(4, DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12'))->days());
    }

    public function testCountsTheDaysLeftFromTodayIncluded(): void
    {
        $range = DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12'));
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertSame(4, $range->daysLeftOn(new \DateTimeImmutable('2026-07-01 10:00', $paris)));
        self::assertSame(3, $range->daysLeftOn(new \DateTimeImmutable('2026-07-10 23:30', $paris)));
        self::assertSame(1, $range->daysLeftOn(new \DateTimeImmutable('2026-07-12 08:00', $paris)));
        self::assertSame(0, $range->daysLeftOn(new \DateTimeImmutable('2026-07-13 08:00', $paris)));
    }

    public function testYearOfUsesParisLocalDate(): void
    {
        self::assertSame(2027, DateRange::yearOf(new \DateTimeImmutable('2026-12-31T23:30:00+00:00')));
        self::assertSame(2026, DateRange::yearOf(new \DateTimeImmutable('2026-12-31T22:30:00+00:00')));
    }
}
