<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\OrderCalendar;
use App\Domain\Reporting\Analysis\SellingDay;
use App\Domain\Reporting\Analysis\SoldEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class OrderCalendarTest extends TestCase
{
    public function testBoundsAreInclusiveAndADaySplitBetweenAnEventAndOffEventCountsBoth(): void
    {
        $calendar = OrderCalendar::of([
            ...Scenario::calendar()->days(),
            new SellingDay('2026-09-26', 3, null),
        ]);

        self::assertSame(207, $calendar->orders());
        self::assertSame(7, $calendar->offEventOrders());
        self::assertSame(160, $calendar->between('2026-07-09', '2026-07-10'));
        self::assertSame(103, $calendar->between('2026-07-10', '2026-09-26'));
        self::assertSame(47, $calendar->since('2026-09-26'));
        self::assertSame(40, $calendar->ordersAt(Scenario::market()));
    }

    public function testEventsComeOldestFirstWithTheBusiestOne(): void
    {
        $calendar = OrderCalendar::of(array_reverse(Scenario::calendar()->days()));

        self::assertSame(['Expo', 'Marché'], array_map(static fn (SoldEvent $event): string => $event->name, $calendar->events()));
        self::assertSame([160, 40], array_map($calendar->ordersAt(...), $calendar->events()));
        self::assertSame(Scenario::expo(), $calendar->busiestEvent());
        self::assertSame(160, $calendar->busiestEventOrders());
    }

    public function testACalendarWithoutEventsHasNoBusiestEvent(): void
    {
        $calendar = OrderCalendar::of([new SellingDay('2026-10-05', 4, null)]);

        self::assertNull($calendar->busiestEvent());
        self::assertSame(0, $calendar->busiestEventOrders());
        self::assertSame(0, $calendar->ordersAt(new SoldEvent(new Ulid(), 'Ailleurs', '2026-10-05', '2026-10-05')));
    }
}
