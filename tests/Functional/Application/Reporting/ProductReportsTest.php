<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Reporting;

use App\Application\Discount\CreateDiscountRule\CreateDiscountRuleHandler;
use App\Application\Discount\DiscountRuleDefinition;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Reporting\ProductsReport\GetProductsReportHandler;
use App\Application\Stock\Restock\Restock;
use App\Application\Stock\Restock\RestockHandler;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\DiscountRules;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProductReportsTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private string $print;
    private string $sticker;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        $container = self::getContainer();
        $type = (string) $container->get(CreateProductTypeHandler::class)('Print')->id();
        $this->print = self::createProduct('Forêt', 1_000, typeId: $type);
        $this->sticker = self::createProduct('Mousse', 300, typeId: (string) $container->get(CreateProductTypeHandler::class)('Sticker')->id());
        $container->get(RestockHandler::class)(new Restock($this->print, null, 10, 4_000));
        $container->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition('2 prints pour 15 €', [DiscountRules::product($this->print, 2)], 'fixedPrice', 1_500, '2030-03-01', '2030-03-31'));
        $container->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-14')));
        $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 12:00'), [new RequestedLine($this->print, null, 2), new RequestedLine($this->sticker, null, 1)]));
        $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 15:00'), [new RequestedLine($this->sticker, null, 2)]));
        $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 16:00'), [new RequestedLine($this->print, null, 1)]));
    }

    public function testBestSellersRankTheRevenueAfterTheirShareOfDiscounts(): void
    {
        $report = self::getContainer()->get(GetProductsReportHandler::class)('2030');

        self::assertSame(['from' => '2030-01-01', 'to' => '2030-12-31'], ['from' => $report->period['from'], 'to' => $report->period['to']]);
        self::assertSame(
            [['Print Forêt', 3, 3_000, 2_500, 500, 7], ['Sticker Mousse', 3, 900, 900, 0, -3]],
            array_map(static fn (array $row): array => [$row['name'], $row['units'], $row['gross'], $row['revenue'], $row['discount'], $row['onHand']], $report->products),
        );
        self::assertSame([[1, 2, 2_000], [3, 0, 0]], array_map(static fn (array $row): array => [$row['fullPriceUnits'], $row['discountedUnits'], $row['discountedGross']], $report->products));
        self::assertSame([3_400, 2], [$report->totals['revenue'], $report->totals['discountedUnits']]);
    }

    public function testTheYearsToChooseAreTheCurrentOneAndThoseWithSales(): void
    {
        $container = self::getContainer();
        $container->get(ScheduleEventHandler::class)(new ScheduleEvent('Marché', 'Lyon', new \DateTimeImmutable('2033-06-04'), new \DateTimeImmutable('2033-06-04')));
        $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2033-06-04 11:00'), [new RequestedLine($this->sticker, null, 1)]));

        $years = $container->get(GetProductsReportHandler::class)('2030')->years;

        self::assertSame([2033, 2030, (int) date('Y')], $years);
    }
}
