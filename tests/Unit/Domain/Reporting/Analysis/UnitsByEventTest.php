<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\Exposure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class UnitsByEventTest extends TestCase
{
    public function testAnEventHeldBeforeTheArrivalOrAfterTheProductSoldOutHasNoFigure(): void
    {
        [$mousse, $lichen, $nouveau, $dormant] = [new Ulid(), new Ulid(), new Ulid(), new Ulid()];
        $units = Scenario::units($mousse, $lichen, $nouveau);
        $calendar = Scenario::calendar();
        $events = $calendar->events();

        self::assertSame([[30, 8], 2], [$units->row($mousse, $events, Exposure::of($calendar, '2026-07-01', 40)), $units->offEvent($mousse)]);
        self::assertSame([12, null], $units->row($lichen, $events, Exposure::of($calendar, '2026-07-01', 12, $units->lastSale($lichen))));
        self::assertSame([12, 0], $units->row($lichen, $events, Exposure::of($calendar, '2026-07-01', 12)));
        self::assertSame([null, 5], $units->row($nouveau, $events, Exposure::of($calendar, '2026-09-20', 5)));
        self::assertSame([[0, 0], 0], [$units->row($dormant, $events, Exposure::of($calendar, '2026-07-01', 0)), $units->offEvent($dormant)]);
    }

    public function testTheFirstAndLastSalesSpanEveryEventAndOutsideEvents(): void
    {
        [$mousse, $lichen, $nouveau] = [new Ulid(), new Ulid(), new Ulid()];
        $units = Scenario::units($mousse, $lichen, $nouveau);

        self::assertSame(['2026-07-09', '2026-10-05'], [$units->firstSale($mousse), $units->lastSale($mousse)]);
        self::assertSame(['2026-07-09', '2026-07-09'], [$units->firstSale($lichen), $units->lastSale($lichen)]);
        self::assertSame([null, null], [$units->firstSale(new Ulid()), $units->lastSale(new Ulid())]);
    }
}
