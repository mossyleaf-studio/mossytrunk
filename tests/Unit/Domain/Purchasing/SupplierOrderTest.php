<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Purchasing;

use App\Domain\Product\Product;
use App\Domain\Purchasing\Exception\InvalidPurchase;
use App\Domain\Purchasing\Exception\NonPositiveLeadTime;
use App\Domain\Purchasing\PurchasedItem;
use App\Domain\Purchasing\Supplier;
use App\Domain\Purchasing\SupplierOrder;
use App\Domain\Purchasing\SupplierOrderStatus;
use App\Domain\Shared\Money;
use App\Tests\Support\TestProductType;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class SupplierOrderTest extends TestCase
{
    private Supplier $printer;
    private Product $sticker;
    private Product $print;

    protected function setUp(): void
    {
        $this->printer = Supplier::create(TestWorkspace::get(), 'Imprimerie du Lac', 'contact@lac.test');
        $this->sticker = Product::create(TestWorkspace::get(), 'STK', 'Sticker', Money::cents(400), TestProductType::get());
        $this->print = Product::create(TestWorkspace::get(), 'PRI', 'Print', Money::cents(1_500), variants: ['A5', 'A4'], type: TestProductType::get());
    }

    public function testAnOrderStartsOrderedWithItsTotal(): void
    {
        $order = $this->order();

        self::assertSame(SupplierOrderStatus::Ordered, $order->status());
        self::assertSame(3_000, $order->total()->amount());
        self::assertSame(120, $order->orderedUnits());
        self::assertNull($order->receivedUnits());
        self::assertSame(20, $order->lines()[0]->plannedUnitCost()->amount());
        self::assertSame('CMF-1', $order->reference());
    }

    public function testReceivingRecomputesTheUnitCostFromWhatActuallyArrived(): void
    {
        $order = $this->order();
        [$stickers, $prints] = $order->lines();

        $received = $order->receive([(string) $stickers->id() => 125, (string) $prints->id() => 18], new \DateTimeImmutable('2026-09-10'));

        self::assertSame(SupplierOrderStatus::Received, $order->status());
        self::assertCount(2, $received);
        self::assertSame(16, $stickers->unitCost()?->amount());
        self::assertSame(56, $prints->unitCost()?->amount());
        self::assertSame(143, $order->receivedUnits());
    }

    public function testNothingReceivedMeansNoStockAndNoUnitCost(): void
    {
        $order = $this->order();
        [$stickers, $prints] = $order->lines();

        $received = $order->receive([(string) $stickers->id() => 100, (string) $prints->id() => 0], new \DateTimeImmutable('2026-09-10'));

        self::assertSame([$stickers], $received);
        self::assertNull($prints->unitCost());
    }

    public function testGlobalDiscountFollowsLinePricesAndDeliveryFeesAreSharedEqually(): void
    {
        $order = SupplierOrder::place('CMF-1', $this->printer, new \DateTimeImmutable('2026-09-01'), [
            new PurchasedItem($this->sticker->sellable(null), 100, Money::cents(3_000)),
            new PurchasedItem($this->print->sellable('A4'), 10, Money::cents(1_000)),
        ], Money::cents(400), Money::cents(500));
        [$stickers, $prints] = $order->lines();

        self::assertSame(4_000, $order->subtotal()->amount());
        self::assertSame(4_100, $order->total()->amount());
        self::assertSame(3_000 - 300 + 250, $stickers->landedCost()->amount());
        self::assertSame(1_000 - 100 + 250, $prints->landedCost()->amount());
        self::assertSame(115, $prints->plannedUnitCost()->amount());

        $order->receive([(string) $stickers->id() => 100, (string) $prints->id() => 5], new \DateTimeImmutable('2026-09-10'));

        self::assertSame(230, $prints->unitCost()?->amount());
    }

    public function testFiveEurosOfDeliveryOverFiveLinesAddOneEuroToEach(): void
    {
        $products = array_map(static fn (int $index): Product => Product::create(TestWorkspace::get(), "P$index", "Produit $index", Money::cents(100), TestProductType::get()), range(1, 5));
        $order = SupplierOrder::place('CMF-1', $this->printer, new \DateTimeImmutable('2026-09-01'), array_map(
            static fn (Product $product): PurchasedItem => new PurchasedItem($product->sellable(null), 10, Money::cents(1_000)),
            $products,
        ), deliveryFees: Money::cents(500));

        self::assertSame([1_100, 1_100, 1_100, 1_100, 1_100], array_map(static fn ($line): int => $line->landedCost()->amount(), $order->lines()));
    }

    public function testGlobalDiscountCannotExceedTheLines(): void
    {
        $this->expectException(InvalidPurchase::class);

        SupplierOrder::place('CMF-1', $this->printer, new \DateTimeImmutable('2026-09-01'), [
            new PurchasedItem($this->sticker->sellable(null), 1, Money::cents(100)),
        ], Money::cents(101));
    }

    public function testEveryLineMustBeCounted(): void
    {
        $order = $this->order();

        $this->expectException(InvalidPurchase::class);

        $order->receive([(string) $order->lines()[0]->id() => 100], new \DateTimeImmutable('2026-09-10'));
    }

    public function testAReceivedOrderCannotChangeAnyMore(): void
    {
        $order = $this->order();
        $order->receive(array_fill_keys(array_map(static fn ($line): string => (string) $line->id(), $order->lines()), 1), new \DateTimeImmutable('2026-09-10'));

        $this->expectException(InvalidPurchase::class);

        $order->revise($this->printer, new \DateTimeImmutable('2026-09-01'), [new PurchasedItem($this->sticker->sellable(null), 1, Money::zero())]);
    }

    public function testAnItemIsOrderedOnce(): void
    {
        $this->expectException(InvalidPurchase::class);

        SupplierOrder::place('CMF-1', $this->printer, new \DateTimeImmutable('2026-09-01'), [
            new PurchasedItem($this->sticker->sellable(null), 10, Money::cents(200)),
            new PurchasedItem($this->sticker->sellable(null), 5, Money::cents(100)),
        ]);
    }

    public function testAnOrderHasAtLeastOneLine(): void
    {
        $this->expectException(InvalidPurchase::class);

        SupplierOrder::place('CMF-1', $this->printer, new \DateTimeImmutable('2026-09-01'), []);
    }

    public function testSupplierNameIsRequired(): void
    {
        $this->expectException(InvalidPurchase::class);

        Supplier::create(TestWorkspace::get(), '  ');
    }

    public function testASupplierHasNoLeadTimeUntilOneIsGiven(): void
    {
        self::assertNull($this->printer->leadDays());

        $supplier = Supplier::create(TestWorkspace::get(), 'Atelier Textile', leadDays: 10);
        self::assertSame(10, $supplier->leadDays());

        $supplier->describe('Atelier Textile', null, null, 14);
        self::assertSame(14, $supplier->leadDays());

        $supplier->describe('Atelier Textile', null, null, null);
        self::assertNull($supplier->leadDays());
    }

    public function testASupplierLeadTimeIsAtLeastOneDay(): void
    {
        $this->expectException(NonPositiveLeadTime::class);

        $this->printer->describe('Imprimerie du Lac', null, null, 0);
    }

    private function order(): SupplierOrder
    {
        return SupplierOrder::place('CMF-1', $this->printer, new \DateTimeImmutable('2026-09-01'), [
            new PurchasedItem($this->sticker->sellable(null), 100, Money::cents(2_000)),
            new PurchasedItem($this->print->sellable('A4'), 20, Money::cents(1_000)),
        ]);
    }

    public function testTheSupplierReferenceIsOptionalTrimmedAndShort(): void
    {
        $order = $this->order();
        self::assertNull($order->supplierReference());

        $order->referToSupplierOrder('  CMD-4521 ');
        self::assertSame('CMD-4521', $order->supplierReference());

        $order->referToSupplierOrder(' ');
        self::assertNull($order->supplierReference());

        $this->expectException(InvalidPurchase::class);
        $order->referToSupplierOrder(str_repeat('X', 101));
    }
}
