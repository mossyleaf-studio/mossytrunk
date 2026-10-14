<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Domain\Discount\AppliedDiscount;
use App\Domain\Event\Event;
use App\Domain\Order\Exception\LineAlreadyIdentified;
use App\Domain\Order\Exception\RefundedOrderLocked;
use App\Domain\Order\Exception\SplitWorthLessThanLine;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\OrderLine;
use App\Domain\Order\PaymentMethod;
use App\Domain\Product\Product;
use App\Domain\Product\SellableItem;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use App\Tests\Support\DomainExceptions;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class OrderSplitLineTest extends TestCase
{
    private Event $event;
    private Product $print;
    private Product $keychain;

    protected function setUp(): void
    {
        $this->event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12')));
        $this->print = Product::create(TestWorkspace::get(), 'PRT', 'Print', Money::cents(400), TestProductType::get());
        $this->keychain = Product::create(TestWorkspace::get(), 'KEY', 'Keychain', Money::cents(1_000), TestProductType::get());
    }

    public function testAnAmountTypedOnTheTerminalIsSplitIntoProductsAndTheServiceDiscountKeepsWhatWasPaid(): void
    {
        $order = $this->typedAmount(1_000, [new OrderedItem($this->print->sellable(null), 1)], 1_200);

        $order->splitLine($this->unknownLine($order)->id(), [
            (new OrderedItem($this->print->sellable(null), 1))->costing(Money::cents(100)),
            (new OrderedItem($this->keychain->sellable(null), 1))->costing(Money::cents(300)),
        ], [], 'Remise SumUp');

        self::assertSame([['Print', 2, 100], ['Keychain', 1, 300]], array_map(static fn (OrderLine $line): array => [$line->label(), $line->quantity(), $line->cost()->amount()], $order->lines()));
        self::assertEquals([new AppliedDiscount('Remise SumUp', Money::cents(600))], $order->appliedDiscounts());
        self::assertSame([1_800, 600, 1_200], [$order->subtotal()->amount(), $order->discountTotal()->amount(), $order->total()->amount()]);
    }

    public function testTheCatalogueDiscountsAreKeptWhenTheyExplainTheGap(): void
    {
        $order = $this->typedAmount(1_000);
        $lot = new AppliedDiscount('Print et porte-clés', Money::cents(399), new Ulid());

        $order->splitLine($this->unknownLine($order)->id(), [new OrderedItem($this->print->sellable(null), 1), new OrderedItem($this->keychain->sellable(null), 1)], [$lot], 'Remise SumUp');

        self::assertEquals([$lot], $order->appliedDiscounts());
        self::assertSame(1_001, $order->total()->amount());
    }

    public function testOnlyAnUnknownLineOfAnOpenOrderIsSplitIntoProductsWorthAtLeastWhatWasPaid(): void
    {
        $order = $this->typedAmount(1_000, [new OrderedItem($this->print->sellable(null), 1)], 1_400);
        $lineId = $this->unknownLine($order)->id();
        $known = $order->lines()[0];

        DomainExceptions::assertThrown(new SplitWorthLessThanLine('Unknown', Money::cents(400), Money::cents(1_000)), fn () => $order->splitLine($lineId, [new OrderedItem($this->print->sellable(null), 1)], [], 'Remise SumUp'));
        DomainExceptions::assertThrown(new LineAlreadyIdentified('Print'), fn () => $order->splitLine($known->id(), [new OrderedItem($this->keychain->sellable(null), 1)], [], 'Remise SumUp'));

        $order->refund(new \DateTimeImmutable('2026-07-11'));
        DomainExceptions::assertThrown(new RefundedOrderLocked('CMD-1'), fn () => $order->splitLine($lineId, [new OrderedItem($this->keychain->sellable(null), 1)], [], 'Remise SumUp'));
    }

    /**
     * @param list<OrderedItem> $others
     */
    private function typedAmount(int $amount, array $others = [], ?int $charged = null): Order
    {
        return Order::imported('CMD-1', TestWorkspace::get(), 'sumup', 'TX1', 'TX1', $this->event, new \DateTimeImmutable('2026-07-10 15:00'), [
            ...$others,
            new OrderedItem(SellableItem::unknown('Unknown', Money::cents($amount)), 1),
        ], Money::cents($charged ?? $amount), Money::zero(), PaymentMethod::Cash, [], 'Remise SumUp');
    }

    private function unknownLine(Order $order): OrderLine
    {
        return array_values(array_filter($order->lines(), static fn (OrderLine $line): bool => $line->sellsUnknownProduct()))[0];
    }
}
