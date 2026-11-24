<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\Exposure;
use PHPUnit\Framework\TestCase;

final class ExposureTest extends TestCase
{
    public function testThePaceAndCoverOfAProductOnSaleTheWholePeriod(): void
    {
        $mousse = Exposure::of(Scenario::calendar(), '2026-07-01', 40);

        self::assertSame([204, 19.6, false, 102], [$mousse->orders, $mousse->per100Orders(), $mousse->tooEarly(), $mousse->coverOrders(20)]);
        self::assertSame(816, Exposure::of(Scenario::calendar(), '2026-07-01', 6)->coverOrders(24));
    }

    public function testASoldOutProductIsExposedOnlyUntilItsLastSaleSoItDoesNotLookUnpopular(): void
    {
        $lichen = Exposure::of(Scenario::calendar(), '2026-07-01', 12, '2026-07-09');

        self::assertSame([100, 12.0, false, 0], [$lichen->orders, $lichen->per100Orders(), $lichen->tooEarly(), $lichen->coverOrders(0)]);
        self::assertTrue($lichen->onSaleDuring(Scenario::expo()));
        self::assertFalse($lichen->onSaleDuring(Scenario::market()));
        self::assertSame(204, Exposure::of(Scenario::calendar(), '2026-07-01', 12)->orders);
    }

    public function testALateArrivalIsTooEarlyToJudge(): void
    {
        $nouveau = Exposure::of(Scenario::calendar(), '2026-09-20', 5);

        self::assertSame([44, 44, 11.4, true, 220], [$nouveau->orders, $nouveau->sinceArrival, $nouveau->per100Orders(), $nouveau->tooEarly(), $nouveau->coverOrders(25)]);
        self::assertFalse($nouveau->onSaleDuring(Scenario::expo()));
        self::assertTrue($nouveau->onSaleDuring(Scenario::market()));
        self::assertTrue(Exposure::of(Scenario::busyYear(), '2026-08-01', 30)->tooEarly());
        self::assertFalse(Exposure::of(Scenario::busyYear(), '2026-01-01', 30)->tooEarly());
    }

    public function testNoOrdersOrNoSalesDoNotDivideByZero(): void
    {
        $dormant = Exposure::of(Scenario::calendar(), '2026-07-01', 0);
        $unseen = Exposure::of(Scenario::calendar(), '2026-12-01', 0);

        self::assertSame([204, 0.0, null], [$dormant->orders, $dormant->per100Orders(), $dormant->coverOrders(20)]);
        self::assertSame([0, null, true], [$unseen->orders, $unseen->per100Orders(), $unseen->tooEarly()]);
    }
}
