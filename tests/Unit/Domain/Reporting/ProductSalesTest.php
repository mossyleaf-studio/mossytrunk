<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reporting;

use App\Domain\Reporting\ProductSales;
use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class ProductSalesTest extends TestCase
{
    public function testTheDiscountIsWhatTheGrossLostAndTheMarginWhatTheSalesKeptOverTheCost(): void
    {
        $sales = new ProductSales('Print Forêt', new Ulid(), 'Print Forêt', null, 4, Money::cents(6_000), Money::cents(5_200), Money::cents(1_600), false, 1, Money::cents(1_500));

        self::assertSame(800, $sales->discount()->amount());
        self::assertSame(3_600, $sales->margin()->amount());
    }
}
