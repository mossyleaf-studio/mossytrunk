<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Reporting;

use App\Application\Discount\CreateDiscountRule\CreateDiscountRuleHandler;
use App\Application\Discount\DiscountRuleDefinition;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Order\AddOrderSupplies\AddOrderSupplies;
use App\Application\Order\AddOrderSupplies\AddOrderSuppliesHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\CreateProduct\CreateProduct;
use App\Application\Product\CreateProduct\CreateProductHandler;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Reporting\ProductReport\GetProductReportHandler;
use App\Application\Reporting\ProductsReport\GetProductsReportHandler;
use App\Application\Sales\OfferSupplies\OfferSuppliesHandler;
use App\Application\Stock\Restock\Restock;
use App\Application\Stock\Restock\RestockHandler;
use App\Application\Stock\TakeStockCheck\CountedItem;
use App\Application\Stock\TakeStockCheck\TakeStockCheck;
use App\Application\Stock\TakeStockCheck\TakeStockCheckHandler;
use App\Domain\Product\ProductKind;
use App\Domain\Sales\SalesChannelRepository;
use App\Domain\Shared\BusinessTime;
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
    private string $event;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        $container = self::getContainer();
        $type = (string) $container->get(CreateProductTypeHandler::class)('Print')->id();
        $this->print = self::createProduct('Forêt', 1_000, typeId: $type);
        $this->sticker = self::createProduct('Mousse', 300, typeId: (string) $container->get(CreateProductTypeHandler::class)('Sticker')->id());
        $container->get(RestockHandler::class)(new Restock($this->print, null, 10, 4_000));
        $container->get(CreateDiscountRuleHandler::class)(new DiscountRuleDefinition('2 prints pour 15 €', [DiscountRules::product($this->print, 2)], 'fixedPrice', 1_500, '2030-03-01', '2030-03-31'));
        $this->event = (string) $container->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-14')));
        $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 12:00'), [new RequestedLine($this->print, null, 2), new RequestedLine($this->sticker, null, 1)]));
        $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 15:00'), [new RequestedLine($this->sticker, null, 2)]));
    }

    public function testBestSellersRankTheRevenueAfterTheirShareOfDiscounts(): void
    {
        $report = self::getContainer()->get(GetProductsReportHandler::class)('2030');

        self::assertSame(['from' => '2030-01-01', 'to' => '2030-12-31'], ['from' => $report->period['from'], 'to' => $report->period['to']]);
        self::assertSame(
            [['Print Forêt', 2, 2_000, 1_565, 435, 8], ['Sticker Mousse', 3, 900, 835, 65, -3]],
            array_map(static fn (array $row): array => [$row['name'], $row['units'], $row['gross'], $row['revenue'], $row['discount'], $row['onHand']], $report->products),
        );
        self::assertSame(2_400, $report->totals['revenue']);
    }

    public function testTheYearsToChooseAreTheCurrentOneAndThoseWithSales(): void
    {
        $container = self::getContainer();
        $container->get(ScheduleEventHandler::class)(new ScheduleEvent('Marché', 'Lyon', new \DateTimeImmutable('2033-06-04'), new \DateTimeImmutable('2033-06-04')));
        $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2033-06-04 11:00'), [new RequestedLine($this->sticker, null, 1)]));

        $years = $container->get(GetProductsReportHandler::class)('2030')->years;

        self::assertSame([2033, 2030, (int) date('Y')], $years);
    }

    public function testAProductTellsItsSalesStockAndDiscountsMonthByMonth(): void
    {
        $report = self::getContainer()->get(GetProductReportHandler::class)($this->print, '2030');

        self::assertCount(12, $report->months);
        [$january, , $march, $april] = $report->months;
        self::assertSame(['2030-03', 2, 2_000, 1_565, 2, 8], [$march['month'], $march['units'], $march['gross'], $march['revenue'], $march['sold'], $march['onHand']]);
        self::assertSame([10, 8], [$january['onHand'], $april['onHand']]);
        self::assertSame([['2 prints pour 15 €', '2030-03-01', '2030-03-31', 31]], array_map(static fn (array $discount): array => [$discount['name'], $discount['from'], $discount['to'], $discount['days']], $report->discounts));
        self::assertSame(31, $report->discountedDays);
    }

    public function testTheStockOfAYearBeforeLaterSalesStillCountsWhatTheySold(): void
    {
        $report = self::getContainer()->get(GetProductReportHandler::class)($this->print, '2029');

        self::assertSame(array_fill(0, 12, [10, 0]), array_map(static fn (array $month): array => [$month['onHand'], $month['sold']], $report->months));
    }

    public function testLossesAndSuppliesUsedAreCountedInTheirMonth(): void
    {
        $container = self::getContainer();
        $sleeve = (string) $container->get(CreateProductHandler::class)(new CreateProduct('Pochette', 0, kind: ProductKind::Supply));
        $container->get(RestockHandler::class)(new Restock($sleeve, null, 100, 500));
        $container->get(OfferSuppliesHandler::class)((string) $container->get(SalesChannelRepository::class)->main()->id(), [$sleeve]);
        $order = (string) $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 16:00'), [new RequestedLine($this->sticker, null, 1)]))->id();
        $container->get(AddOrderSuppliesHandler::class)(new AddOrderSupplies([$order], [new RequestedLine($sleeve, null, 3)]));
        $container->get(TakeStockCheckHandler::class)(new TakeStockCheck($this->event, [new CountedItem($this->print, null, 7)]));

        $now = new \DateTimeImmutable();
        $print = $container->get(GetProductReportHandler::class)($this->print, $now->format('Y'));
        $thisMonth = array_values(array_filter($print->months, static fn (array $month): bool => BusinessTime::month($now) === $month['month']))[0];
        self::assertSame([10, 1, 9], [$thisMonth['received'], $thisMonth['lost'], $thisMonth['onHand']]);

        $march = $container->get(GetProductReportHandler::class)($sleeve, '2030')->months[2];
        self::assertSame([3, 97], [$march['used'], $march['onHand']]);
    }
}
