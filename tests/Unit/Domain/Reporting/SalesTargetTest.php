<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting;

use App\Domain\Reporting\SalesFigures;
use App\Domain\Reporting\SalesTarget;
use App\Domain\Reporting\SalesTotals;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class SalesTargetTest extends TestCase
{
    public function testTheTargetCoversExpensesUrssafAndTheMedianCostRateOfPastEvents(): void
    {
        $target = SalesTarget::of(Money::cents(30_000), 2, [
            self::past(10_000, 3_000, 1),
            self::past(20_000, 2_000, 2),
            self::past(30_000, 7_500, 1),
            self::past(0, 0, 1),
        ]);

        self::assertSame(2_500, $target->costRateBasisPoints);
        self::assertSame(3, $target->basedOnEvents);
        self::assertSame(10_000, $target->usualPerDay?->amount());
        self::assertSame(48_232, $target->total?->amount());
        self::assertSame(24_116, $target->perDay?->amount());
    }

    public function testWithoutPastSalesOnlyExpensesAndUrssafCount(): void
    {
        $target = SalesTarget::of(Money::cents(8_720), 1, []);

        self::assertSame([0, 0, null, 10_000, 10_000], [$target->costRateBasisPoints, $target->basedOnEvents, $target->usualPerDay, $target->total?->amount(), $target->perDay?->amount()]);
    }

    public function testTheMedianOfAnEvenNumberOfEventsIsTheMeanOfTheTwoMiddleOnes(): void
    {
        $target = SalesTarget::of(Money::zero(), 1, [self::past(10_000, 1_000, 1), self::past(10_000, 3_000, 2)]);

        self::assertSame([2_000, 7_500], [$target->costRateBasisPoints, $target->usualPerDay?->amount()]);
    }

    public function testATargetIsOutOfReachWhenCostsEatTheWholeMargin(): void
    {
        $target = SalesTarget::of(Money::cents(10_000), 1, [self::past(10_000, 9_000, 1)]);

        self::assertNull($target->total);
        self::assertNull($target->perDay);
    }

    /**
     * @return array{figures: SalesFigures, days: int}
     */
    private static function past(int $turnover, int $costOfGoods, int $days): array
    {
        return ['figures' => SalesFigures::of(new SalesTotals(1, Money::cents($turnover), Money::zero(), Money::zero(), Money::cents($costOfGoods), Money::zero(), Money::zero()), Money::zero()), 'days' => $days];
    }
}
