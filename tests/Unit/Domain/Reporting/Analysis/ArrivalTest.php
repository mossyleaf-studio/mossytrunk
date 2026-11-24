<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting\Analysis;

use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use App\Domain\Reporting\Analysis\Arrival;
use App\Domain\Shared\Money;
use App\Domain\Stock\LotOrigin;
use App\Domain\Stock\StockItem;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class ArrivalTest extends TestCase
{
    public function testStockTypedAfterTheFirstSaleArrivesOnTheFirstSale(): void
    {
        $sticker = self::sticker();

        $arrival = Arrival::of($sticker, [self::stock($sticker, '2026-08-01T10:00:00+02:00')], '2026-07-09');

        self::assertSame('2026-07-09', $arrival?->day);
    }

    public function testALotReceivedLateInTheEveningArrivesOnItsParisDay(): void
    {
        $sticker = self::sticker();

        $arrival = Arrival::of($sticker, [self::stock($sticker, '2026-07-02T00:00:00+02:00'), self::stock($sticker, '2026-06-30T22:30:00+00:00')], '2026-07-09');

        self::assertSame('2026-07-01', $arrival?->day);
        self::assertTrue($arrival->isBy('2026-07-01'));
        self::assertFalse($arrival->isBy('2026-06-30'));
    }

    public function testSuppliesArchivedProductsAndProductsNeverStockedNorSoldAreLeftOut(): void
    {
        $type = ProductType::create(TestWorkspace::get(), 'Sticker', 'STK');
        $sleeve = Product::supply(TestWorkspace::get(), 'POC', 'Pochette', $type);
        $archived = self::sticker();
        $archived->archive(new \DateTimeImmutable('2026-08-01'));

        self::assertNull(Arrival::of(self::sticker(), [], null));
        self::assertNull(Arrival::of(self::sticker(), [StockItem::open(self::sticker(), null)], null));
        self::assertNull(Arrival::of($sleeve, [self::stock($sleeve, '2026-07-01')], '2026-07-09'));
        self::assertNull(Arrival::of($archived, [self::stock($archived, '2026-07-01')], '2026-07-09'));
    }

    private static function sticker(): Product
    {
        return Product::create(TestWorkspace::get(), 'MOU', 'Sticker Mousse', Money::cents(300), ProductType::create(TestWorkspace::get(), 'Sticker', 'STK'));
    }

    private static function stock(Product $product, string $receivedAt): StockItem
    {
        $stock = StockItem::open($product, null);
        $stock->receive(10, Money::cents(500), LotOrigin::Purchase, new \DateTimeImmutable($receivedAt));

        return $stock;
    }
}
