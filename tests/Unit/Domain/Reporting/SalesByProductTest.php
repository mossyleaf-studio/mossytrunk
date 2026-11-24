<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting;

use App\Domain\Reporting\ProductSales;
use App\Domain\Reporting\SalesByProduct;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class SalesByProductTest extends TestCase
{
    public function testFindsAProductAndRanksBySales(): void
    {
        $print = new Ulid();
        $sticker = new Ulid();

        $sales = SalesByProduct::of([
            new ProductSales('Sticker', $sticker, 'Sticker', null, 2, Money::cents(800), Money::cents(800), Money::zero(), true, 0, Money::zero()),
            new ProductSales('Print', $print, 'Print', null, 3, Money::cents(4_500), Money::cents(4_500), Money::cents(900), false, 0, Money::zero()),
            new ProductSales('Inconnu', null, 'Inconnu', null, 1, Money::cents(9_000), Money::cents(9_000), Money::zero(), true, 0, Money::zero()),
        ]);

        self::assertSame(['Print', 'Sticker'], array_map(static fn (ProductSales $product): string => $product->productName, $sales->ranked()));
        self::assertSame(3, $sales->forProduct($print)?->quantity);
        self::assertTrue($sales->forProduct($sticker)?->unknownCost);
        self::assertNull(SalesByProduct::of([])->forProduct($print));
    }
}
