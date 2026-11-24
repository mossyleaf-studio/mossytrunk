<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\Exposure;
use App\Domain\Reporting\Analysis\MarginClass;
use App\Domain\Reporting\Analysis\OrderCalendar;
use App\Domain\Reporting\Analysis\Quadrant;
use App\Domain\Reporting\Analysis\Reason;
use App\Domain\Reporting\Analysis\StockLevel;
use App\Domain\Reporting\Analysis\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReasonTest extends TestCase
{
    /**
     * @return iterable<string, array{OrderCalendar, string, int, MarginClass, ?Quadrant, StockLevel, Reason, Verdict}>
     */
    public static function products(): iterable
    {
        yield 'under 150 orders since arrival is too early, even well sold' => [Scenario::calendar(), '2026-09-20', 5, MarginClass::B, null, StockLevel::Out, Reason::TooFewOrders, Verdict::TooEarly];
        yield 'under 150 orders since arrival is too early, even unsold' => [Scenario::calendar(), '2026-09-20', 0, MarginClass::C, null, StockLevel::Ok, Reason::TooFewOrders, Verdict::TooEarly];
        yield 'an arrival after half of the period\'s orders is too early' => [Scenario::busyYear(), '2026-08-01', 30, MarginClass::A, Quadrant::Star, StockLevel::Low, Reason::ArrivedLate, Verdict::TooEarly];
        yield 'nothing sold is discontinued' => [Scenario::calendar(), '2026-07-01', 0, MarginClass::C, null, StockLevel::Ok, Reason::NothingSold, Verdict::Discontinue];
        yield 'unpopular in class C is discontinued, even sold out' => [Scenario::calendar(), '2026-07-01', 2, MarginClass::C, Quadrant::Dog, StockLevel::Out, Reason::UnpopularTail, Verdict::Discontinue];
        yield 'unpopular in class C is discontinued, even profitable' => [Scenario::calendar(), '2026-07-01', 2, MarginClass::C, Quadrant::Puzzle, StockLevel::Ok, Reason::UnpopularTail, Verdict::Discontinue];
        yield 'popular and low is restocked' => [Scenario::calendar(), '2026-07-01', 40, MarginClass::A, Quadrant::Star, StockLevel::Low, Reason::LowCover, Verdict::Restock];
        yield 'class A sold out is restocked, even unpopular' => [Scenario::calendar(), '2026-07-01', 12, MarginClass::A, Quadrant::Puzzle, StockLevel::Out, Reason::SoldOut, Verdict::Restock];
        yield 'a popular workhorse sold out is restocked before repriced' => [Scenario::calendar(), '2026-07-01', 20, MarginClass::B, Quadrant::Workhorse, StockLevel::Out, Reason::SoldOut, Verdict::Restock];
        yield 'a workhorse in stock is repriced' => [Scenario::calendar(), '2026-07-01', 20, MarginClass::A, Quadrant::Workhorse, StockLevel::Ok, Reason::Workhorse, Verdict::Reprice];
        yield 'unpopular outside class A is watched' => [Scenario::calendar(), '2026-07-01', 8, MarginClass::B, Quadrant::Puzzle, StockLevel::Ok, Reason::Unpopular, Verdict::Watch];
        yield 'unpopular outside class A is watched, even low' => [Scenario::calendar(), '2026-07-01', 8, MarginClass::B, Quadrant::Dog, StockLevel::Low, Reason::Unpopular, Verdict::Watch];
        yield 'a star in stock is kept' => [Scenario::calendar(), '2026-07-01', 6, MarginClass::A, Quadrant::Star, StockLevel::Ok, Reason::Fine, Verdict::Keep];
        yield 'unpopular in class A is kept for its margin' => [Scenario::calendar(), '2026-07-01', 6, MarginClass::A, Quadrant::Dog, StockLevel::Ok, Reason::TopEarner, Verdict::Keep];
        yield 'a popular star in class C is kept' => [Scenario::calendar(), '2026-07-01', 6, MarginClass::C, Quadrant::Star, StockLevel::Ok, Reason::Fine, Verdict::Keep];
    }

    #[DataProvider('products')]
    public function testTheFirstMatchingCauseGivesTheReasonAndItsVerdict(OrderCalendar $calendar, string $arrival, int $units, MarginClass $class, ?Quadrant $quadrant, StockLevel $stock, Reason $reason, Verdict $verdict): void
    {
        $found = Reason::of(Exposure::of($calendar, $arrival, $units), $class, $quadrant, $stock);

        self::assertSame([$reason, $verdict], [$found, $found->verdict()]);
    }
}
