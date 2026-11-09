<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Planning;

use App\Domain\Event\Event;
use App\Domain\Planning\Exception\DayAndEvent;
use App\Domain\Planning\Exception\DayOrEventMissing;
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

    public function testARestockByADayIsOrderedLeadDaysBefore(): void
    {
        $restock = Restock::plan($this->printer, '  stickers holo ', BusinessTime::at('2026-07-09 14:00'), null, 10);

        self::assertSame($this->printer, $restock->supplier());
        self::assertSame(TestWorkspace::get(), $restock->workspace());
        self::assertSame('stickers holo', $restock->note());
        self::assertSame('2026-07-09', $restock->day()?->format('Y-m-d'));
        self::assertNull($restock->event());
        self::assertSame(10, $restock->leadDays());
        self::assertSame('2026-07-09 00:00', $restock->targetDay()->format('Y-m-d H:i'));
        self::assertSame('2026-06-29 00:00', $restock->orderBy()->format('Y-m-d H:i'));
        self::assertFalse($restock->isOrdered());
        self::assertNull($restock->orderedOn());
        self::assertNull($restock->supplierOrder());
    }

    public function testABlankNoteIsNone(): void
    {
        self::assertNull(Restock::plan($this->printer, '  ', BusinessTime::at('2026-07-09'), null, 1)->note());
    }

    public function testARestockByAnEventFollowsItsReschedule(): void
    {
        $event = self::event('2026-07-09', '2026-07-12');

        $restock = Restock::plan($this->printer, null, null, $event, 7);

        self::assertSame($event, $restock->event());
        self::assertNull($restock->day());
        self::assertSame('2026-07-09', $restock->targetDay()->format('Y-m-d'));
        self::assertSame('2026-07-02', $restock->orderBy()->format('Y-m-d'));

        $event->reschedule(DateRange::fromDates(BusinessTime::at('2026-08-01'), BusinessTime::at('2026-08-02')));

        self::assertSame('2026-07-25', $restock->orderBy()->format('Y-m-d'));
    }

    public function testTheOrderByDayStaysAParisDayAcrossDaylightSaving(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-04-02'), null, 7);

        self::assertSame('2026-03-26 00:00 Europe/Paris', $restock->orderBy()->format('Y-m-d H:i e'));
        self::assertSame('2026-03-26', BusinessTime::day($restock->orderBy()));
    }

    public function testTheLeadTimeIsAtLeastOneDay(): void
    {
        $this->expectException(NonPositiveLeadTime::class);

        Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 0);
    }

    public function testARestockNeedsADayOrAnEvent(): void
    {
        $this->expectException(DayOrEventMissing::class);

        Restock::plan($this->printer, null, null, null, 5);
    }

    public function testARestockCannotHaveBothADayAndAnEvent(): void
    {
        $this->expectException(DayAndEvent::class);

        Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), self::event('2026-07-09', '2026-07-12'), 5);
    }

    public function testReviseChangesEverything(): void
    {
        $restock = Restock::plan($this->printer, 'stickers', BusinessTime::at('2026-07-09'), null, 10);
        $textile = Supplier::create(TestWorkspace::get(), 'Atelier Textile');
        $event = self::event('2026-09-05', '2026-09-06');

        $restock->revise($textile, 'tote bags', null, $event, 20);

        self::assertSame($textile, $restock->supplier());
        self::assertSame('tote bags', $restock->note());
        self::assertNull($restock->day());
        self::assertSame($event, $restock->event());
        self::assertSame(20, $restock->leadDays());
        self::assertSame('2026-08-16', $restock->orderBy()->format('Y-m-d'));
    }

    public function testReviseKeepsTheRules(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);

        $this->expectException(NonPositiveLeadTime::class);

        $restock->revise($this->printer, null, BusinessTime::at('2026-07-09'), null, -2);
    }

    public function testReviseCannotMoveAnOrderedRestockAwayFromItsOrdersSupplier(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);
        $restock->orderedThrough($this->order($this->printer, '2026-06-28'));

        $this->expectException(RestockFromAnotherSupplier::class);

        $restock->revise(Supplier::create(TestWorkspace::get(), 'Atelier Textile'), null, BusinessTime::at('2026-07-09'), null, 10);
    }

    public function testTickingMarksItOrderedOnThatDayThenUntickingClearsIt(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);

        $restock->markOrdered(BusinessTime::at('2026-06-30 18:45'));

        self::assertTrue($restock->isOrdered());
        self::assertSame('2026-06-30', $restock->orderedOn()?->format('Y-m-d'));

        $restock->markNotOrdered();

        self::assertFalse($restock->isOrdered());
        self::assertNull($restock->orderedOn());
    }

    public function testASupplierOrderOfItsSupplierOrdersIt(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);
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
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);
        $restock->orderedThrough($this->order($this->printer, '2026-06-28'));

        $restock->markOrdered(BusinessTime::at('2026-06-30'));

        self::assertNull($restock->supplierOrder());
        self::assertSame('2026-06-30', $restock->orderedOn()?->format('Y-m-d'));
    }

    public function testItFollowsTheNewDateOfItsOrder(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);
        $order = $this->order($this->printer, '2026-06-28');
        $restock->orderedThrough($order);

        $order->revise($this->printer, new \DateTimeImmutable('2026-06-25'), $this->items());
        $restock->followOrder();

        self::assertSame($order, $restock->supplierOrder());
        self::assertSame('2026-06-25', $restock->orderedOn()?->format('Y-m-d'));
    }

    public function testItLeavesAnOrderMovedToAnotherSupplierAndStaysOrdered(): void
    {
        $restock = Restock::plan($this->printer, 'stickers', BusinessTime::at('2026-07-09'), null, 10);
        $order = $this->order($this->printer, '2026-06-28');
        $restock->orderedThrough($order);

        $order->revise(Supplier::create(TestWorkspace::get(), 'Atelier Textile'), new \DateTimeImmutable('2026-06-27'), $this->items());
        $restock->followOrder();

        self::assertNull($restock->supplierOrder());
        self::assertSame('2026-06-27', $restock->orderedOn()?->format('Y-m-d'));
        $restock->revise($this->printer, 'stickers holo', BusinessTime::at('2026-07-09'), null, 10);
        self::assertSame('stickers holo', $restock->note());
    }

    public function testFollowingWithoutAnOrderChangesNothing(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);

        $restock->followOrder();

        self::assertFalse($restock->isOrdered());
    }

    public function testASupplierOrderOfAnotherSupplierCannotOrderIt(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);

        $this->expectExceptionObject(new RestockFromAnotherSupplier('Imprimerie du Lac'));

        $restock->orderedThrough($this->order(Supplier::create(TestWorkspace::get(), 'Atelier Textile'), '2026-06-28'));
    }

    public function testARestockIsPastOnceItsTargetDayIsBehindToday(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);

        self::assertFalse($restock->isPastOn(BusinessTime::at('2026-07-08 23:00')));
        self::assertFalse($restock->isPastOn(BusinessTime::at('2026-07-09 22:00')));
        self::assertTrue($restock->isPastOn(BusinessTime::at('2026-07-10 00:30')));
    }

    public function testItsStateFollowsTheOrderByDay(): void
    {
        $restock = Restock::plan($this->printer, null, BusinessTime::at('2026-07-09'), null, 10);

        self::assertSame(StepState::Upcoming, $restock->stateOn(BusinessTime::at('2026-06-28 23:30')));
        self::assertSame(StepState::Upcoming, $restock->stateOn(BusinessTime::at('2026-06-29 23:30')));
        self::assertSame(StepState::Late, $restock->stateOn(BusinessTime::at('2026-06-30 00:10')));

        $restock->markOrdered(BusinessTime::at('2026-06-30'));

        self::assertSame(StepState::Done, $restock->stateOn(BusinessTime::at('2026-06-30 00:10')));
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
