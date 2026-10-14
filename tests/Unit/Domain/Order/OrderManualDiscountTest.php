<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Order;

use App\Domain\Discount\AppliedDiscount;
use App\Domain\Discount\ConditionSpec;
use App\Domain\Discount\DiscountAction;
use App\Domain\Discount\DiscountRule;
use App\Domain\Discount\Exception\DiscountNotValidOn;
use App\Domain\Discount\Exception\NotAManualDiscount;
use App\Domain\Discount\ValidityPeriod;
use App\Domain\Event\Event;
use App\Domain\Order\Exception\DiscountAlreadyGranted;
use App\Domain\Order\Exception\RefundedOrderLocked;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Product\Product;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Exception\NotFound;
use App\Domain\Shared\Money;
use App\Tests\Support\DomainExceptions;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class OrderManualDiscountTest extends TestCase
{
    private Event $event;
    private Product $sticker;

    protected function setUp(): void
    {
        $this->event = Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-12')));
        $this->sticker = Product::create(TestWorkspace::get(), 'STK', 'Sticker', Money::cents(400), TestProductType::get());
    }

    public function testAGiftTakesWhatTheAutomaticDiscountsLeftToPay(): void
    {
        $order = $this->order([new AppliedDiscount('3 stickers pour 10 €', Money::cents(200))]);

        $order->grantDiscount($this->manual('Cadeau', DiscountAction::percentOff(10_000)));

        self::assertSame(['3 stickers pour 10 €' => 200, 'Cadeau' => 1_000], $this->amounts($order));
        self::assertSame(0, $order->total()->amount());
        self::assertTrue($order->appliedDiscounts()[1]->isManual());
    }

    public function testManualDiscountsFollowEachOtherAndAreSettledAgainWhenOneIsWithdrawn(): void
    {
        $order = $this->order();
        $tenPercent = $this->manual('Fidélité', DiscountAction::percentOff(1_000));
        $order->grantDiscount($tenPercent);
        $order->grantDiscount($this->manual('Prix ami', DiscountAction::fixedPrice(Money::cents(800))));
        $order->grantDiscount($this->manual('Bon d\'achat', DiscountAction::amountOff(Money::cents(5_000))));

        self::assertSame(['Fidélité' => 120, 'Prix ami' => 280, 'Bon d\'achat' => 800], $this->amounts($order));

        $order->withdrawDiscount($tenPercent->id());

        self::assertSame(['Prix ami' => 400, 'Bon d\'achat' => 800], $this->amounts($order));
        self::assertSame(0, $order->total()->amount());
    }

    public function testOnlyAManualDiscountValidOnTheOrderDateIsGrantedOnceOnAnOrderStillOpen(): void
    {
        $order = $this->order();
        $gift = $this->manual('Cadeau', DiscountAction::percentOff(10_000));
        $order->grantDiscount($gift);

        DomainExceptions::assertThrown(new DiscountAlreadyGranted('Cadeau', 'CMD-1'), fn () => $order->grantDiscount($gift));
        DomainExceptions::assertThrown(new NotAManualDiscount('Lot'), fn () => $order->grantDiscount(DiscountRule::create(TestWorkspace::get(), 'Lot', [ConditionSpec::on(1, $this->sticker)], DiscountAction::percentOff(1_000))));
        DomainExceptions::assertThrown(new DiscountNotValidOn('Été', $order->placedAt()), fn () => $order->grantDiscount($this->manual('Été', DiscountAction::percentOff(1_000), ValidityPeriod::between(new \DateTimeImmutable('2026-08-01'), null))));
        DomainExceptions::assertThrown(new NotFound('discount', (string) $gift->id()), fn () => $this->order()->withdrawDiscount($gift->id()));

        $order->refund(new \DateTimeImmutable('2026-07-11'));
        DomainExceptions::assertThrown(new RefundedOrderLocked('CMD-1'), fn () => $order->withdrawDiscount($gift->id()));
    }

    /**
     * @param list<AppliedDiscount> $discounts
     */
    private function order(array $discounts = []): Order
    {
        return Order::place('CMD-1', $this->event, new \DateTimeImmutable('2026-07-10 15:00', new \DateTimeZone('Europe/Paris')), [new OrderedItem($this->sticker->sellable(null), 3)], $discounts);
    }

    private function manual(string $name, DiscountAction $action, ?ValidityPeriod $validity = null): DiscountRule
    {
        return DiscountRule::create(TestWorkspace::get(), $name, [], $action, $validity, true);
    }

    /**
     * @return array<string, int>
     */
    private function amounts(Order $order): array
    {
        return array_column(array_map(static fn (AppliedDiscount $discount): array => [$discount->label, $discount->amount->amount()], $order->appliedDiscounts()), 1, 0);
    }
}
