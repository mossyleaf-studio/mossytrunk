<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Planning;

use App\Domain\Event\Event;
use App\Domain\Planning\Exception\RestockFromAnotherSupplier;
use App\Domain\Planning\Restock;
use App\Domain\Planning\StepState;
use App\Domain\Product\Product;
use App\Domain\Purchasing\Exception\NonPositiveLeadTime;
use App\Domain\Purchasing\PurchasedItem;
use App\Domain\Purchasing\Supplier;
use App\Domain\Purchasing\SupplierOrder;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class RestockTest extends TestCase
{
    private Supplier $printer;

    protected function setUp(): void
    {
        $this->printer = Supplier::create(TestWorkspace::get(), 'Imprimerie du Lac');
    }

    public function testARestockForAnEventIsOrderedLeadDaysBeforeItsReadyByDay(): void
    {
        $event = self::event('2026-07-12', '2026-07-13');

        $restock = Restock::plan($this->printer, '  stickers holo ', $event, 10);

        self::assertSame($this->printer, $restock->supplier());
        self::assertSame(TestWorkspace::get(), $restock->workspace());
        self::assertSame('stickers holo', $restock->note());
        self::assertSame($event, $restock->event());
        self::assertNull($restock->day());
        self::assertSame(10, $restock->leadDays());
        self::assertSame('2026-07-12 00:00', $restock->targetDay()->format('Y-m-d H:i'));
        self::assertSame('2026-07-09 00:00', $restock->readyBy(3)->format('Y-m-d H:i'));
        self::assertSame('2026-06-29 00:00', $restock->orderBy(3)->format('Y-m-d H:i'));
        self::assertFalse($restock->isOrdered());
        self::assertNull($restock->orderedOn());
        self::assertNull($restock->supplierOrder());
    }

    public function testTheEventsOwnMarginWinsAndItFollowsItsReschedule(): void
    {
        $event = self::event('2026-07-12', '2026-07-13');
        $restock = Restock::plan($this->printer, null, $event, 7);

        $event->planReadiness(0);

        self::assertSame('2026-07-05', BusinessTime::day($restock->orderBy(3)));

        $event->reschedule(DateRange::fromDates(BusinessTime::at('2026-08-01'), BusinessTime::at('2026-08-02')));

        self::assertSame('2026-07-25', BusinessTime::day($restock->orderBy(3)));
    }

    public function testABlankNoteIsNone(): void
    {
        self::assertNull(Restock::plan($this->printer, '  ', self::event('2026-07-09', '2026-07-12'), 1)->note());
    }

    public function testTheOrderByDayStaysAParisDayAcrossDaylightSaving(): void
    {
        $restock = Restock::plan($this->printer, null, self::event('2026-04-05', '2026-04-06'), 7);

        self::assertSame('2026-03-26 00:00 Europe/Paris', $restock->orderBy(3)->format('Y-m-d H:i e'));
        self::assertSame('2026-03-26', BusinessTime::day($restock->orderBy(3)));
    }

    public function testTheLeadTimeIsAtLeastOneDay(): void
    {
        $this->expectException(NonPositiveLeadTime::class);

        Restock::plan($this->printer, null, self::event('2026-07-09', '2026-07-12'), 0);
    }

    public function testALegacyRestockOnADayKeepsThatDayAsItsTarget(): void
    {
        $restock = self::onLegacyDay($this->restock(), '2026-07-09');

        self::assertNull($restock->event());
        self::assertSame('2026-07-09', $restock->day()?->format('Y-m-d'));
        self::assertSame('2026-07-09 00:00', $restock->targetDay()->format('Y-m-d H:i'));
        self::assertSame('2026-07-09 00:00', $restock->readyBy(3)->format('Y-m-d H:i'));
        self::assertSame('2026-06-29 00:00', $restock->orderBy(3)->format('Y-m-d H:i'));
        self::assertSame(BusinessTime::day($restock->orderBy(3)), BusinessTime::day($restock->orderBy(7)));
    }

    public function testReviseChangesEverything(): void
    {
        $restock = Restock::plan($this->printer, 'stickers', self::event('2026-07-09', '2026-07-12'), 10);
        $textile = Supplier::create(TestWorkspace::get(), 'Atelier Textile');
        $event = self::event('2026-09-08', '2026-09-09');

        $restock->revise($textile, 'tote bags', $event, 20);

        self::assertSame($textile, $restock->supplier());
        self::assertSame('tote bags', $restock->note());
        self::assertSame($event, $restock->event());
        self::assertSame(20, $restock->leadDays());
        self::assertSame('2026-08-16', BusinessTime::day($restock->orderBy(3)));
    }

    public function testRevisingALegacyRestockMovesItOntoAnEvent(): void
    {
        $restock = self::onLegacyDay($this->restock(), '2026-07-09');
        $event = self::event('2026-09-08', '2026-09-09');

        $restock->revise($this->printer, null, $event, 10);

        self::assertNull($restock->day());
        self::assertSame($event, $restock->event());
        self::assertSame('2026-09-08', BusinessTime::day($restock->targetDay()));
        self::assertSame('2026-08-26', BusinessTime::day($restock->orderBy(3)));
    }

    public function testReviseKeepsTheRules(): void
    {
        $restock = $this->restock();

        $this->expectException(NonPositiveLeadTime::class);

        $restock->revise($this->printer, null, self::event('2026-07-09', '2026-07-12'), -2);
    }

    public function testReviseCannotMoveAnOrderedRestockAwayFromItsOrdersSupplier(): void
    {
        $restock = $this->restock();
        $restock->orderedThrough($this->order($this->printer, '2026-06-28'));

        $this->expectException(RestockFromAnotherSupplier::class);

        $restock->revise(Supplier::create(TestWorkspace::get(), 'Atelier Textile'), null, self::event('2026-07-12', '2026-07-13'), 10);
    }

    public function testTickingMarksItOrderedOnThatDayThenUntickingClearsIt(): void
    {
        $restock = $this->restock();

        $restock->markOrdered(BusinessTime::at('2026-06-30 18:45'));

        self::assertTrue($restock->isOrdered());
        self::assertSame('2026-06-30', $restock->orderedOn()?->format('Y-m-d'));

        $restock->markNotOrdered();

        self::assertFalse($restock->isOrdered());
        self::assertNull($restock->orderedOn());
    }

    public function testASupplierOrderOfItsSupplierOrdersIt(): void
    {
        $restock = $this->restock();
        $order = $this->order($this->printer, '2026-06-28');

        $restock->orderedThrough($order);

        self::assertTrue($restock->isOrdered());
        self::assertSame($order, $restock->supplierOrder());
        self::assertSame('2026-06-28', $restock->orderedOn()?->format('Y-m-d'));

        $restock->markNotOrdered();

        self::assertNull($restock->supplierOrder());
        self::assertNull($restock->orderedOn());
    }

    public function testTickingALinkedRestockDropsTheLink(): void
    {
        $restock = $this->restock();
        $restock->orderedThrough($this->order($this->printer, '2026-06-28'));

        $restock->markOrdered(BusinessTime::at('2026-06-30'));

        self::assertNull($restock->supplierOrder());
        self::assertSame('2026-06-30', $restock->orderedOn()?->format('Y-m-d'));
    }

    public function testItFollowsTheNewDateOfItsOrder(): void
    {
        $restock = $this->restock();
        $order = $this->order($this->printer, '2026-06-28');
        $restock->orderedThrough($order);

        $order->revise($this->printer, new \DateTimeImmutable('2026-06-25'), $this->items());
        $restock->followOrder();

        self::assertSame($order, $restock->supplierOrder());
        self::assertSame('2026-06-25', $restock->orderedOn()?->format('Y-m-d'));
    }

    public function testItLeavesAnOrderMovedToAnotherSupplierAndStaysOrdered(): void
    {
        $restock = $this->restock('stickers');
        $order = $this->order($this->printer, '2026-06-28');
        $restock->orderedThrough($order);

        $order->revise(Supplier::create(TestWorkspace::get(), 'Atelier Textile'), new \DateTimeImmutable('2026-06-27'), $this->items());
        $restock->followOrder();

        self::assertNull($restock->supplierOrder());
        self::assertSame('2026-06-27', $restock->orderedOn()?->format('Y-m-d'));
        $restock->revise($this->printer, 'stickers holo', self::event('2026-07-12', '2026-07-13'), 10);
        self::assertSame('stickers holo', $restock->note());
    }

    public function testFollowingWithoutAnOrderChangesNothing(): void
    {
        $restock = $this->restock();

        $restock->followOrder();

        self::assertFalse($restock->isOrdered());
    }

    public function testASupplierOrderOfAnotherSupplierCannotOrderIt(): void
    {
        $restock = $this->restock();

        $this->expectExceptionObject(new RestockFromAnotherSupplier('Imprimerie du Lac'));

        $restock->orderedThrough($this->order(Supplier::create(TestWorkspace::get(), 'Atelier Textile'), '2026-06-28'));
    }

    public function testARestockIsPastOnceItsEventHasStarted(): void
    {
        $restock = $this->restock();

        self::assertFalse($restock->isPastOn(BusinessTime::at('2026-07-11 23:00')));
        self::assertFalse($restock->isPastOn(BusinessTime::at('2026-07-12 22:00')));
        self::assertTrue($restock->isPastOn(BusinessTime::at('2026-07-13 00:30')));
    }

    public function testALegacyRestockIsPastOnceItsDayIsBehindToday(): void
    {
        $restock = self::onLegacyDay($this->restock(), '2026-07-09');

        self::assertFalse($restock->isPastOn(BusinessTime::at('2026-07-09 22:00')));
        self::assertTrue($restock->isPastOn(BusinessTime::at('2026-07-10 00:30')));
    }

    public function testItsStateFollowsTheOrderByDay(): void
    {
        $restock = $this->restock();

        self::assertSame(StepState::Upcoming, $restock->stateOn(BusinessTime::at('2026-06-28 23:30'), 3));
        self::assertSame(StepState::Upcoming, $restock->stateOn(BusinessTime::at('2026-06-29 23:30'), 3));
        self::assertSame(StepState::Late, $restock->stateOn(BusinessTime::at('2026-06-30 00:10'), 3));
        self::assertSame(StepState::Upcoming, $restock->stateOn(BusinessTime::at('2026-06-30 00:10'), 2));

        $restock->markOrdered(BusinessTime::at('2026-06-30'));

        self::assertSame(StepState::Done, $restock->stateOn(BusinessTime::at('2026-06-30 00:10'), 3));
    }

    private function restock(?string $note = null): Restock
    {
        return Restock::plan($this->printer, $note, self::event('2026-07-12', '2026-07-13'), 10);
    }

    private static function onLegacyDay(Restock $restock, string $day): Restock
    {
        (new \ReflectionProperty(Restock::class, 'event'))->setValue($restock, null);
        (new \ReflectionProperty(Restock::class, 'day'))->setValue($restock, BusinessTime::at($day));

        return $restock;
    }

    private function order(Supplier $supplier, string $orderedOn): SupplierOrder
    {
        return SupplierOrder::place('CMF-1', $supplier, new \DateTimeImmutable($orderedOn), $this->items());
    }

    /**
     * @return list<PurchasedItem>
     */
    private function items(): array
    {
        $sticker = Product::create(TestWorkspace::get(), 'STK', 'Sticker', Money::cents(400), TestProductType::get());

        return [new PurchasedItem($sticker->sellable(null), 100, Money::cents(2_000))];
    }

    private static function event(string $start, string $end): Event
    {
        return Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', DateRange::fromDates(BusinessTime::at($start), BusinessTime::at($end)));
    }
}
