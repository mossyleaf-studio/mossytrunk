<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Domain\Discount\AppliedDiscount;
use App\Domain\Event\Event;
use App\Domain\Order\Exception\InvalidOrder;
use App\Domain\Order\Exception\OrderAlreadyRefunded;
use App\Domain\Order\Exception\OrdersNotMergeable;
use App\Domain\Order\Exception\RefundedOrderLocked;
use App\Domain\Order\ImportedSale;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\PaymentMethod;
use App\Domain\Product\Product;
use App\Domain\Product\SellableItem;
use App\Domain\Sales\ChannelKind;
use App\Domain\Sales\Exception\MarketOrderWithoutEvent;
use App\Domain\Sales\SalesChannel;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Exception\NegativeAmount;
use App\Domain\Shared\Money;
use App\Tests\Support\Costs;
use App\Tests\Support\DomainExceptions;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class OrderTest extends TestCase
{
    private Event $event;
    private Product $sticker;
    private Product $tshirt;

    protected function setUp(): void
    {
        $this->event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12')));
        $this->sticker = Costs::bought(Product::create(TestWorkspace::get(), 'STK', 'Sticker', Money::cents(400), TestProductType::get()), 80);
        $this->tshirt = Costs::bought(Product::create(TestWorkspace::get(), 'TS', 'T-shirt', Money::cents(2_000), TestProductType::get(), ['S', 'M']), 900);
    }

    public function testTotalsAndCostOfGoods(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [
            new OrderedItem($this->sticker->sellable(null), 3),
            new OrderedItem($this->tshirt->sellable('M'), 1),
        ], [new AppliedDiscount('3 stickers pour 10 €', Money::cents(200))]);

        self::assertSame(3_200, $order->subtotal()->amount());
        self::assertSame(200, $order->discountTotal()->amount());
        self::assertSame(3_000, $order->total()->amount());
        self::assertSame(1_140, $order->costOfGoods()->amount());
        self::assertSame(4, $order->itemCount());
        self::assertSame(Order::MANUAL, $order->source());
        self::assertSame('CMD-1', $order->reference());
    }

    public function testAManualOrderOnAnOnlineChannelHasNoEventAndKeepsAComment(): void
    {
        $gifts = SalesChannel::open(TestWorkspace::get(), 'Cadeaux', ChannelKind::Direct);
        $order = Order::placeOn('CMD-1', $gifts, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], []);
        $order->annotate('  Cadeau pour Léa ');

        self::assertSame([null, $gifts, Order::MANUAL, 400, 'Cadeau pour Léa'], [$order->event(), $order->channel(), $order->source(), $order->total()->amount(), $order->note()]);

        $order->annotate('');
        self::assertNull($order->note());

        $this->expectException(MarketOrderWithoutEvent::class);
        Order::placeOn('CMD-2', SalesChannel::main(TestWorkspace::get(), 'Marchés'), self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], []);
    }

    public function testEachLineRecordsItsShareOfTheDiscountsInProportionToItsAmount(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [
            new OrderedItem($this->sticker->sellable(null), 3),
            new OrderedItem($this->tshirt->sellable('M'), 1),
        ], [new AppliedDiscount('3 stickers pour 10 €', Money::cents(200))]);

        self::assertSame([[75, 1_125], [125, 1_875]], array_map(static fn ($line): array => [$line->discount()->amount(), $line->revenue()->amount()], $order->lines()));
    }

    public function testTheRecordedFiguresFollowTheChangesOfTheOrder(): void
    {
        $order = Order::imported('CMD-1', TestWorkspace::get(), 'sumup', 'TX1', 'TX1', $this->event, self::at('2026-07-10 15:00'), [
            new OrderedItem($this->sticker->sellable(null), 2),
            new OrderedItem(SellableItem::unknown('Badge', Money::cents(500)), 1),
        ], Money::cents(1_200), Money::zero(), PaymentMethod::Card, [], 'Remise SumUp');
        self::assertSame([1_300, 100, 1_200, 160], [$order->subtotal()->amount(), $order->discountTotal()->amount(), $order->total()->amount(), $order->costOfGoods()->amount()]);

        $order->identifyLine($order->lines()[1]->id(), $this->tshirt->sellable('S')->at(Money::cents(500)), Money::cents(900));

        self::assertSame(1_060, $order->costOfGoods()->amount());
        self::assertSame([62, 38], array_map(static fn ($line): int => $line->discount()->amount(), $order->lines()));
    }

    public function testCostsFromStockAreKeptAndMergedPerLine(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [
            (new OrderedItem($this->sticker->sellable(null), 2))->costing(Money::cents(150)),
            (new OrderedItem($this->sticker->sellable(null), 1))->costing(Money::cents(100)),
        ], []);

        self::assertSame(250, $order->costOfGoods()->amount());
        self::assertSame(83, $order->lines()[0]->unitCost()->amount());
    }

    public function testAnEtsyOrderHasNoEventAndItsShippingCountsInTheTotal(): void
    {
        $order = Order::imported('CMD-1', TestWorkspace::get(), 'etsy', '310', 'ETSY-310', null, self::at('2026-10-02 09:00'), [new OrderedItem($this->sticker->sellable(null)->at(Money::cents(450)), 2)], Money::cents(800), Money::cents(290), PaymentMethod::Card, [], 'Remise Etsy');

        self::assertNull($order->event());
        self::assertSame('etsy', $order->source());
        self::assertSame([['310', 'ETSY-310']], array_map(static fn (ImportedSale $sale): array => [$sale->externalId(), $sale->reference()], $order->importedSales()));
        self::assertSame('CMD-1', $order->reference());
        self::assertEquals([new AppliedDiscount('Remise Etsy', Money::cents(100))], $order->appliedDiscounts());
        self::assertSame([900, 100, 290, 1_090], [$order->subtotal()->amount(), $order->discountTotal()->amount(), $order->shipping()->amount(), $order->total()->amount()]);
    }

    public function testAMarketChannelOnlyTakesOrdersPlacedAtAnEvent(): void
    {
        $items = [new OrderedItem($this->sticker->sellable(null), 1)];
        $markets = SalesChannel::main(TestWorkspace::get(), 'Marchés');
        $etsy = SalesChannel::open(TestWorkspace::get(), 'Etsy', ChannelKind::Online, 'etsy');

        self::assertSame($markets, Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), $items, [], $markets)->channel());
        self::assertSame($etsy, Order::imported('CMD-2', TestWorkspace::get(), 'etsy', '311', 'ETSY-311', null, self::at('2026-10-02 09:00'), $items, Money::cents(400), Money::zero(), PaymentMethod::Card, [], 'Remise Etsy', $etsy)->channel());

        $this->expectException(MarketOrderWithoutEvent::class);
        Order::imported('CMD-3', TestWorkspace::get(), 'etsy', '312', 'ETSY-312', null, self::at('2026-10-02 09:00'), $items, Money::cents(400), Money::zero(), PaymentMethod::Card, [], 'Remise Etsy', $markets);
    }

    public function testSameProductVariantTupleIsMerged(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [
            new OrderedItem($this->tshirt->sellable('M'), 1),
            new OrderedItem($this->tshirt->sellable('S'), 1),
            new OrderedItem($this->tshirt->sellable('M'), 2),
        ], []);

        self::assertCount(2, $order->lines());
        self::assertSame(3, $order->lines()[0]->quantity());
        self::assertSame('T-shirt — M', $order->lines()[0]->label());
    }

    public function testPricesAreSnapshots(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], []);

        $this->sticker->reprice(Money::cents(500));
        $this->sticker->bought(Money::cents(100));

        self::assertSame(400, $order->total()->amount());
        self::assertSame(80, $order->costOfGoods()->amount());
    }

    public function testDateMustBeWithinEvent(): void
    {
        $this->expectException(InvalidOrder::class);

        Order::place('CMD-1', $this->event, self::at('2026-07-13 10:00'), [new OrderedItem($this->sticker->sellable(null), 1)], []);
    }

    public function testLastDayOfEventIsIncluded(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-12 23:59'), [new OrderedItem($this->sticker->sellable(null), 1)], []);

        self::assertSame(400, $order->total()->amount());
    }

    public function testOrderNeedsAtLeastOneItem(): void
    {
        $this->expectException(InvalidOrder::class);

        Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [], []);
    }

    public function testQuantityMustBePositive(): void
    {
        $this->expectException(InvalidOrder::class);

        Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 0)], []);
    }

    public function testDiscountsCannotExceedSubtotal(): void
    {
        $this->expectException(InvalidOrder::class);

        Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], [new AppliedDiscount('Trop', Money::cents(500))]);
    }

    public function testAnImportedOrderKeepsItsExternalIdAndTheGapAsTheServiceDiscount(): void
    {
        $order = $this->imported('TX123', 3, 1_000);

        self::assertSame([['sumup', 'TX123', 'TX123']], array_map(static fn (ImportedSale $sale): array => [$sale->source(), $sale->externalId(), $sale->reference()], $order->importedSales()));
        self::assertSame('sumup', $order->source());
        self::assertEquals([new AppliedDiscount('Remise SumUp', Money::cents(200))], $order->appliedDiscounts());
        self::assertSame(1_000, $order->total()->amount());
    }

    public function testAnImportedOrderKeepsTheMatchingRuleDiscountDespiteRounding(): void
    {
        $rule = new AppliedDiscount('3 stickers pour 10 €', Money::cents(200), new Ulid());

        $exact = $this->imported('TX125', 3, 1_000, [$rule]);
        $rounded = $this->imported('TX126', 3, 1_002, [$rule]);

        self::assertEquals([$rule], $exact->appliedDiscounts());
        self::assertEquals([$rule], $rounded->appliedDiscounts());
        self::assertSame(1_200, $rounded->subtotal()->amount());
        self::assertSame(1_000, $rounded->total()->amount());
    }

    public function testAnImportedOrderFallsBackToTheServiceDiscountWhenNoRuleMatches(): void
    {
        $rule = new AppliedDiscount('3 stickers pour 10 €', Money::cents(200), new Ulid());

        self::assertEquals([new AppliedDiscount('Remise SumUp', Money::cents(197))], $this->imported('TX127', 3, 1_003, [$rule])->appliedDiscounts());
    }

    public function testAnImportedOrderWithoutDiscount(): void
    {
        $order = Order::imported('CMD-1', TestWorkspace::get(), 'sumup', 'TX124', 'TX124', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], Money::cents(400), Money::zero(), PaymentMethod::Cash, [], 'Remise SumUp');

        self::assertSame([], $order->appliedDiscounts());
        self::assertSame(PaymentMethod::Cash, $order->paymentMethod());
    }

    public function testAnImportedOrderCannotHaveNegativeShipping(): void
    {
        $this->expectException(InvalidOrder::class);

        Order::imported('CMD-1', TestWorkspace::get(), 'etsy', '311', 'ETSY-311', null, self::at('2026-10-02 09:00'), [new OrderedItem($this->sticker->sellable(null), 1)], Money::cents(400), Money::cents(-1), PaymentMethod::Card, [], 'Remise Etsy');
    }

    public function testTwoSalesOfOneCustomerAreMergedIntoOneOrder(): void
    {
        $card = Order::imported('CMD-1', TestWorkspace::get(), 'sumup', 'TX-CARD', 'TX-CARD', $this->event, self::at('2026-07-10 15:02'), [
            (new OrderedItem($this->sticker->sellable(null), 2))->costing(Money::cents(160)),
            (new OrderedItem($this->tshirt->sellable('M'), 1))->costing(Money::cents(900)),
        ], Money::cents(2_700), Money::zero(), PaymentMethod::Card, [], 'Remise SumUp');
        $cash = Order::imported('CMD-1', TestWorkspace::get(), 'sumup', 'TX-CASH', 'TX-CASH', $this->event, self::at('2026-07-10 15:00'), [
            (new OrderedItem($this->sticker->sellable(null), 1))->costing(Money::cents(80)),
        ], Money::cents(400), Money::zero(), PaymentMethod::Cash, [], 'Remise SumUp');

        $card->absorb($cash);

        self::assertSame([['Sticker', 3, 240], ['T-shirt — M', 1, 900]], array_map(static fn ($line): array => [$line->label(), $line->quantity(), $line->cost()->amount()], $card->lines()));
        self::assertSame([3_200, 100, 3_100], [$card->subtotal()->amount(), $card->discountTotal()->amount(), $card->total()->amount()]);
        self::assertSame(['TX-CARD', 'TX-CASH'], array_map(static fn (ImportedSale $sale): string => $sale->reference(), $card->importedSales()));
        self::assertSame(PaymentMethod::Mixed, $card->paymentMethod());
        self::assertEquals(self::at('2026-07-10 15:00'), $card->placedAt());
        self::assertSame([], $cash->importedSales());
    }

    public function testTheFeesOfAnOrderAreKnownOnceTheServiceReportedThemForEachOfItsSales(): void
    {
        $order = $this->imported('TX1', 1, 400);
        $order->absorb($this->imported('TX2', 1, 400));

        self::assertNull($order->saleFees());

        $order->settleSaleFee('sumup', 'TX1', Money::cents(7));
        $order->settleSaleFee('etsy', 'TX2', Money::cents(5));
        self::assertNull($order->saleFees(), 'a fee is settled on the sale of its own service');

        $order->settleSaleFee('sumup', 'TX2', Money::cents(5));
        self::assertSame(12, $order->saleFees()?->amount());
        self::assertSame([7, 5], array_map(static fn (ImportedSale $sale): ?int => $sale->fee()?->amount(), $order->importedSales()));
    }

    public function testAManualOrderHasNoReportedFeesAndAFeeIsNeverNegative(): void
    {
        self::assertNull(Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], [])->saleFees());
        DomainExceptions::assertThrown(new NegativeAmount('payment_fee'), fn () => $this->imported('TX1', 1, 400)->settleSaleFee('sumup', 'TX1', Money::cents(-1)));
    }

    public function testOrdersOfDifferentEventsOrSourcesAreNotMerged(): void
    {
        $other = Event::schedule(TestWorkspace::get(), 'Salon', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01')));
        $manual = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], []);

        DomainExceptions::assertThrown(new OrdersNotMergeable('different_source'), fn () => $this->imported('TX1', 1, 400)->absorb($manual));
        DomainExceptions::assertThrown(new OrdersNotMergeable('different_event'), fn () => Order::place('CMD-1', $other, self::at('2026-08-01 10:00'), [new OrderedItem($this->sticker->sellable(null), 1)], [])->absorb($manual));
        DomainExceptions::assertThrown(new OrdersNotMergeable('same_order'), static fn () => $manual->absorb($manual));
    }

    public function testAnOrderCanAbsorbAnotherUnrefundedOrderOfItsEventAndSource(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], []);
        $sameEvent = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:05'), [new OrderedItem($this->sticker->sellable(null), 1)], []);
        $refunded = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:10'), [new OrderedItem($this->sticker->sellable(null), 1)], []);
        $refunded->refund(self::at('2026-07-20 10:00'));

        self::assertTrue($order->canAbsorb($sameEvent));
        self::assertFalse($order->canAbsorb($order));
        self::assertFalse($order->canAbsorb($refunded));
        self::assertFalse($order->canAbsorb($this->imported('TX1', 1, 400)));
    }

    public function testAnOrderIsTickedOffOnceCheckedAndCanBeUnticked(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), 1)], []);
        self::assertNull($order->checkedAt());

        $order->check(self::at('2026-07-13 09:00'));
        $order->check(self::at('2026-07-14 09:00'));
        self::assertEquals(self::at('2026-07-13 09:00'), $order->checkedAt());

        $order->uncheck();
        self::assertNull($order->checkedAt());
    }

    public function testARefundedOrderIsRefundedOnceAndCannotBeChangedAnymore(): void
    {
        $order = $this->imported('TX1', 1, 400);
        $line = $order->lines()[0];

        $order->refund(self::at('2026-07-20 10:00'));

        self::assertTrue($order->isRefunded());
        self::assertEquals(self::at('2026-07-20 10:00'), $order->refundedAt());
        DomainExceptions::assertThrown(new OrderAlreadyRefunded($order->reference()), static fn () => $order->refund(self::at('2026-07-21 10:00')));
        DomainExceptions::assertThrown(new OrdersNotMergeable('refunded'), fn () => $this->imported('TX2', 1, 400)->absorb($order));
        DomainExceptions::assertThrown(new RefundedOrderLocked($order->reference()), static fn () => $order->lineToIdentify($line->id()));
    }

    /**
     * @param list<AppliedDiscount> $rules
     */
    private function imported(string $code, int $stickers, int $charged, array $rules = []): Order
    {
        return Order::imported('CMD-1', TestWorkspace::get(), 'sumup', $code, $code, $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($this->sticker->sellable(null), $stickers)], Money::cents($charged), Money::zero(), null, $rules, 'Remise SumUp');
    }

    private static function at(string $localTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($localTime, new \DateTimeZone('Europe/Paris'));
    }

    public function testMovedSalesKeepTheirPricesAndMergeWithTheSameItemAtTheSamePrices(): void
    {
        $tee = Costs::bought(Product::create(TestWorkspace::get(), 'TEE', 'Tee', Money::cents(2_000), TestProductType::get(), ['M']), 900);
        $order = Order::place('CMD-1', $this->event, new \DateTimeImmutable('2026-07-10 12:00'), [
            new OrderedItem($this->tshirt->sellable('M'), 1),
            new OrderedItem($tee->sellable('M'), 2),
            new OrderedItem($this->tshirt->sellable('S'), 1),
        ], []);

        $order->moveSales($this->tshirt->id(), 'M', $tee->sellable('M'));

        self::assertEqualsCanonicalizing(['Tee — M' => 3, 'T-shirt — S' => 1], array_combine(
            array_map(static fn ($line): string => $line->label(), $order->lines()),
            array_map(static fn ($line): int => $line->quantity(), $order->lines()),
        ));
        self::assertSame(8_000, $order->total()->amount());
    }

    public function testMovedSalesAtAnotherPriceStayOnTheirOwnLine(): void
    {
        $order = Order::place('CMD-1', $this->event, new \DateTimeImmutable('2026-07-10 12:00'), [new OrderedItem($this->sticker->sellable(null), 2)], []);
        $cheaper = Costs::bought(Product::create(TestWorkspace::get(), 'MUG', 'Mug', Money::cents(300), TestProductType::get()), 80);
        $order2 = Order::place('CMD-1', $this->event, new \DateTimeImmutable('2026-07-10 12:00'), [new OrderedItem($cheaper->sellable(null), 1), new OrderedItem($this->sticker->sellable(null), 1)], []);

        $order->moveSales($this->sticker->id(), null, $cheaper->sellable(null));
        $order2->moveSales($this->sticker->id(), null, $cheaper->sellable(null));

        self::assertSame(['Mug'], array_map(static fn ($line): string => $line->label(), $order->lines()));
        self::assertSame(800, $order->total()->amount());
        self::assertCount(2, $order2->lines());
    }

    public function testImportedSalesMovedOntoAProductTakeItsPriceAndCostWhileTheChargedTotalStays(): void
    {
        $created = Product::create(TestWorkspace::get(), 'BOB', 'Bobcat', Money::cents(250), TestProductType::get());
        $print = Product::create(TestWorkspace::get(), 'PRI', 'Print Bobcat', Money::cents(300), TestProductType::get());
        $order = Order::imported('CMD-1', TestWorkspace::get(), 'sumup', 'TX1', 'TX1', $this->event, self::at('2026-07-10 15:00'), [new OrderedItem($created->sellable(null), 2)], Money::cents(450), Money::zero(), PaymentMethod::Card, [], 'Remise SumUp');

        $order->moveSales($created->id(), null, $print->sellable(null), Money::cents(80), 'Remise SumUp');

        self::assertSame([600, 160], [$order->lines()[0]->total()->amount(), $order->costOfGoods()->amount()]);
        self::assertEquals([new AppliedDiscount('Remise SumUp', Money::cents(150))], $order->appliedDiscounts());
        self::assertSame(450, $order->total()->amount());
    }

    public function testAmountsWithoutProductStayOnTheirOwnLines(): void
    {
        $order = Order::place('CMD-1', $this->event, self::at('2026-07-10 15:00'), [
            new OrderedItem(SellableItem::unknown('Produit inconnu', Money::cents(1_000)), 1),
            new OrderedItem(SellableItem::unknown('Produit inconnu', Money::cents(200)), 1),
        ], []);

        self::assertSame([[true, 1_000], [true, 200]], array_map(static fn ($line): array => [$line->sellsUnknownProduct(), $line->total()->amount()], $order->lines()));
        self::assertTrue($order->costOfGoods()->isZero());
    }
}
