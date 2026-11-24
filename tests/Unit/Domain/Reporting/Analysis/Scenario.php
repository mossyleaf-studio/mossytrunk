<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\EventUnits;
use App\Domain\Reporting\Analysis\OrderCalendar;
use App\Domain\Reporting\Analysis\SellingDay;
use App\Domain\Reporting\Analysis\SoldEvent;
use App\Domain\Reporting\Analysis\UnitsByEvent;
use Symfony\Component\Uid\Ulid;

final class Scenario
{
    private static ?SoldEvent $expo = null;
    private static ?SoldEvent $market = null;

    public static function expo(): SoldEvent
    {
        return self::$expo ??= new SoldEvent(new Ulid(), 'Expo', '2026-07-09', '2026-07-10');
    }

    public static function market(): SoldEvent
    {
        return self::$market ??= new SoldEvent(new Ulid(), 'Marché', '2026-09-26', '2026-09-26');
    }

    public static function calendar(): OrderCalendar
    {
        return OrderCalendar::of([
            new SellingDay('2026-07-09', 100, self::expo()),
            new SellingDay('2026-07-10', 60, self::expo()),
            new SellingDay('2026-09-26', 40, self::market()),
            new SellingDay('2026-10-05', 4, null),
        ]);
    }

    public static function units(Ulid $mousse, Ulid $lichen, Ulid $nouveau): UnitsByEvent
    {
        return UnitsByEvent::of([
            new EventUnits($mousse, self::expo()->id, 30, '2026-07-09', '2026-07-10'),
            new EventUnits($mousse, self::market()->id, 8, '2026-09-26', '2026-09-26'),
            new EventUnits($mousse, null, 2, '2026-10-05', '2026-10-05'),
            new EventUnits($lichen, self::expo()->id, 12, '2026-07-09', '2026-07-09'),
            new EventUnits($nouveau, self::market()->id, 5, '2026-09-26', '2026-09-26'),
        ]);
    }
}
