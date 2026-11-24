<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Reporting\Analysis\Exposure;
use App\Domain\Reporting\Analysis\MarginClass;
use App\Domain\Reporting\Analysis\Quadrant;
use App\Domain\Reporting\Analysis\StockLevel;
use App\Domain\Reporting\Analysis\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerdictTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, MarginClass, ?Quadrant, StockLevel, Verdict}>
     */
    public static function products(): iterable
    {
        yield 'a late arrival is too early, even well sold' => ['2026-09-20', 5, MarginClass::B, null, StockLevel::Out, Verdict::TooEarly];
        yield 'a late arrival is too early, even unsold' => ['2026-09-20', 0, MarginClass::C, null, StockLevel::Ok, Verdict::TooEarly];
        yield 'nothing sold is discontinued' => ['2026-07-01', 0, MarginClass::C, null, StockLevel::Ok, Verdict::Discontinue];
        yield 'unpopular in class C is discontinued, even sold out' => ['2026-07-01', 2, MarginClass::C, Quadrant::Dog, StockLevel::Out, Verdict::Discontinue];
        yield 'unpopular in class C is discontinued, even profitable' => ['2026-07-01', 2, MarginClass::C, Quadrant::Puzzle, StockLevel::Ok, Verdict::Discontinue];
        yield 'popular and low is restocked' => ['2026-07-01', 40, MarginClass::A, Quadrant::Star, StockLevel::Low, Verdict::Restock];
        yield 'class A sold out is restocked, even unpopular' => ['2026-07-01', 12, MarginClass::A, Quadrant::Puzzle, StockLevel::Out, Verdict::Restock];
        yield 'a popular workhorse sold out is restocked before repriced' => ['2026-07-01', 20, MarginClass::B, Quadrant::Workhorse, StockLevel::Out, Verdict::Restock];
        yield 'a workhorse in stock is repriced' => ['2026-07-01', 20, MarginClass::A, Quadrant::Workhorse, StockLevel::Ok, Verdict::Reprice];
        yield 'unpopular outside class A is watched' => ['2026-07-01', 8, MarginClass::B, Quadrant::Puzzle, StockLevel::Ok, Verdict::Watch];
        yield 'unpopular outside class A is watched, even low' => ['2026-07-01', 8, MarginClass::B, Quadrant::Dog, StockLevel::Low, Verdict::Watch];
        yield 'a star in stock is kept' => ['2026-07-01', 6, MarginClass::A, Quadrant::Star, StockLevel::Ok, Verdict::Keep];
        yield 'unpopular in class A is kept' => ['2026-07-01', 6, MarginClass::A, Quadrant::Dog, StockLevel::Ok, Verdict::Keep];
        yield 'a popular star in class C is kept' => ['2026-07-01', 6, MarginClass::C, Quadrant::Star, StockLevel::Ok, Verdict::Keep];
    }

    #[DataProvider('products')]
    public function testTheFirstMatchingRuleGivesTheVerdict(string $arrival, int $units, MarginClass $class, ?Quadrant $quadrant, StockLevel $stock, Verdict $verdict): void
    {
        self::assertSame($verdict, Verdict::of(Exposure::of(Scenario::calendar(), $arrival, $units), $class, $quadrant, $stock));
    }
}
