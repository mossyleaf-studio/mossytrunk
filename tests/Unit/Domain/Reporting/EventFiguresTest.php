<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting;

use App\Domain\Discount\AppliedDiscount;
use App\Domain\Event\Event;
use App\Domain\Event\ExpenseShares;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Product\Product;
use App\Domain\Reporting\SalesFigures;
use App\Domain\Reporting\SalesTotals;
use App\Domain\Reporting\UrssafContribution;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use App\Tests\Support\Costs;
use App\Tests\Support\RecordedSales;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class EventFiguresTest extends TestCase
{
    public function testUrssafRateIs12Point8Percent(): void
    {
        self::assertSame(1_280, UrssafContribution::on(Money::cents(10_000))->amount());
    }

    public function testDonationsAddToTheResultButNotToTheTurnoverNorUrssaf(): void
    {
        $figures = SalesFigures::of(SalesTotals::zero(), Money::cents(1_000), null, Money::cents(2_500));

        self::assertSame([0, 0, 2_500, 1_500], [$figures->turnover->amount(), $figures->urssaf->amount(), $figures->donations->amount(), $figures->result->amount()]);
    }

    public function testTheEventResultSubtractsCostOfGoodsExpensesAndUrssafFromTheTurnover(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12')));
        $event->addExpense('Stand', Money::cents(15_000));
        $event->addExpense('Train', Money::cents(5_000));

        $sticker = Costs::bought(Product::create(TestWorkspace::get(), 'STK', 'Sticker', Money::cents(400), TestProductType::get()), 100);
        $tshirt = Costs::bought(Product::create(TestWorkspace::get(), 'TS', 'T-shirt', Money::cents(2_000), TestProductType::get(), ['S', 'M']), 800);
        $print = Product::create(TestWorkspace::get(), 'PRT', 'Print', Money::cents(1_500), TestProductType::get());
        $at = new \DateTimeImmutable('2026-07-10 14:00', new \DateTimeZone('Europe/Paris'));

        $orders = [
            Order::place('CMD-1', $event, $at, [new OrderedItem($sticker->sellable(null), 3), new OrderedItem($tshirt->sellable('M'), 1)], [new AppliedDiscount('3 pour 10', Money::cents(200))]),
            Order::place('CMD-1', $event, $at, [new OrderedItem($tshirt->sellable('M'), 2), new OrderedItem($print->sellable(null), 1)], []),
            Order::place('CMD-1', $event, $at, [new OrderedItem($tshirt->sellable('S'), 1)], []),
        ];
        $result = SalesFigures::of(RecordedSales::totals($orders), ExpenseShares::among([$event])->totalOf($event));

        self::assertSame(3, $result->orderCount);
        self::assertSame(10_700, $result->grossSales->amount());
        self::assertSame(200, $result->discounts->amount());
        self::assertSame(10_500, $result->turnover->amount());
        self::assertSame(3_500, $result->costOfGoods->amount());
        self::assertSame(20_000, $result->expenses->amount());
        self::assertSame(1_344, $result->urssaf->amount());
        self::assertSame(10_500 - 3_500 - 20_000 - 1_344, $result->result->amount());
    }

    public function testEventWithoutOrdersOnlyHasExpenses(): void
    {
        $event = Event::schedule(TestWorkspace::get(), 'Marché', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-05')));
        $event->addExpense('Stand', Money::cents(4_000));

        $result = SalesFigures::of(SalesTotals::zero(), ExpenseShares::among([$event])->totalOf($event));

        self::assertTrue($result->urssaf->isZero());
        self::assertSame(-4_000, $result->result->amount());
    }
}
