<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\Exposure;
use App\Domain\Reporting\Analysis\Quadrant;
use App\Domain\Reporting\Analysis\TypeBenchmark;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class TypeBenchmarkTest extends TestCase
{
    public function testEachProductIsPlacedAmongTheJudgedProductsOfItsType(): void
    {
        $stickers = [
            'mousse' => [Exposure::of(Scenario::calendar(), '2026-07-01', 40), Money::cents(10_000)],
            'holo' => [Exposure::of(Scenario::calendar(), '2026-07-01', 20), Money::cents(3_000)],
            'lichen' => [Exposure::of(Scenario::calendar(), '2026-07-01', 12, '2026-07-09'), Money::cents(3_000)],
            'fougere' => [Exposure::of(Scenario::calendar(), '2026-07-01', 8), Money::cents(2_000)],
            'nouveau' => [Exposure::of(Scenario::calendar(), '2026-09-20', 5), Money::cents(1_250)],
            'dormant' => [Exposure::of(Scenario::calendar(), '2026-07-01', 0), Money::zero()],
        ];
        $benchmark = TypeBenchmark::none();
        foreach ($stickers as [$exposure, $margin]) {
            $benchmark = $benchmark->with($exposure, $margin);
        }

        self::assertSame([5, 9.1, 6.4, 225, 191], [$benchmark->judged, $benchmark->averagePace(), $benchmark->popularFrom(), $benchmark->averageUnitMargin()?->amount(), $benchmark->unitMarginFrom()?->amount()]);
        self::assertSame(
            ['mousse' => Quadrant::Star, 'holo' => Quadrant::Workhorse, 'lichen' => Quadrant::Star, 'fougere' => Quadrant::Puzzle, 'nouveau' => null, 'dormant' => null],
            array_map(static fn (array $sticker): ?Quadrant => $benchmark->quadrant($sticker[0], $sticker[1]), $stickers),
        );
        self::assertSame(Quadrant::Dog, Quadrant::of(false, false));
    }

    public function testTheThresholdsUseThePaceAsShownSoALoneProductIsAStar(): void
    {
        $print = Exposure::of(Scenario::calendar(), '2026-07-01', 6);
        $benchmark = TypeBenchmark::none()->with($print, Money::cents(4_800));

        self::assertSame([1, 2.0, 680], [$benchmark->judged, $benchmark->popularFrom(), $benchmark->unitMarginFrom()?->amount()]);
        self::assertSame(Quadrant::Star, $benchmark->quadrant($print, Money::cents(4_800)));
    }

    public function testProductsAtTheSamePriceDoNotFlipOnACentOfCost(): void
    {
        $cheaper = Exposure::of(Scenario::calendar(), '2026-07-01', 10);
        $dearer = Exposure::of(Scenario::calendar(), '2026-07-01', 10);
        $benchmark = TypeBenchmark::none()->with($cheaper, Money::cents(2_510))->with($dearer, Money::cents(2_490));

        self::assertSame([Quadrant::Star, Quadrant::Star], [$benchmark->quadrant($cheaper, Money::cents(2_510)), $benchmark->quadrant($dearer, Money::cents(2_490))]);
    }

    public function testATypeWithoutJudgedSalesHasNoThresholds(): void
    {
        $benchmark = TypeBenchmark::none()->with(Exposure::of(Scenario::calendar(), '2026-07-01', 0), Money::zero());

        self::assertSame([1, 0.0, 0.0, null, null], [$benchmark->judged, $benchmark->averagePace(), $benchmark->popularFrom(), $benchmark->averageUnitMargin(), $benchmark->unitMarginFrom()]);
        self::assertSame([null, null, null], [TypeBenchmark::none()->averagePace(), TypeBenchmark::none()->popularFrom(), TypeBenchmark::unitMargin(Money::cents(500), 0)]);
        self::assertSame(250, TypeBenchmark::unitMargin(Money::cents(10_000), 40)?->amount());
    }

    public function testAProductSoldOutBeforeThePeriodDoesNotLowerThePaceOfItsType(): void
    {
        $mousse = Exposure::of(Scenario::calendar(), '2026-07-01', 40);
        $benchmark = TypeBenchmark::none()->with($mousse, Money::cents(10_000))->with(Exposure::of(Scenario::calendar(), '2026-07-01', 0, '2026-06-30'), Money::zero());

        self::assertSame([1, 13.7], [$benchmark->judged, $benchmark->popularFrom()]);
    }
}
