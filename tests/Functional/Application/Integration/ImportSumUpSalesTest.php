<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Discount\CreateDiscountRule\CreateDiscountRuleHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\Exception\ServiceNotAdded;
use App\Application\Integration\ExternalSale;
use App\Application\Integration\ImportSales\ImportReport;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\IdentifyOrderLine\IdentifyOrderLine;
use App\Application\Order\IdentifyOrderLine\IdentifyOrderLineHandler;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Order\ListOrders\OrderSummaryView;
use App\Application\Order\MergeOrders\MergeOrdersHandler;
use App\Application\Order\RequestedLine;
use App\Application\Order\SplitOrderLine\SplitOrderLine;
use App\Application\Order\SplitOrderLine\SplitOrderLineHandler;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Product\DeleteProduct\DeleteProductHandler;
use App\Application\Product\ListProducts\ListProductsHandler;
use App\Application\Product\ListProductTypes\ListProductTypesHandler;
use App\Application\Product\MoveVariant\MoveVariant;
use App\Application\Product\MoveVariant\MoveVariantHandler;
use App\Application\Product\RecordSellingPrice\RecordSellingPriceHandler;
use App\Application\Product\UpdateProduct\UpdateProduct;
use App\Application\Product\UpdateProduct\UpdateProductHandler;
use App\Application\Sales\CreateChannel\CreateChannelHandler;
use App\Application\Stock\Restock\Restock;
use App\Application\Stock\Restock\RestockHandler;
use App\Domain\Order\Exception\LineAlreadyIdentified;
use App\Domain\Order\PaymentMethod;
use App\Domain\Sales\ChannelKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Connector\SumUp\FakeSumUpGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\DiscountRules;
use App\Tests\Support\ExternalSales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class ImportSumUpSalesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    protected function setUp(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        self::actAsMemberOf();
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'sumup', ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test']);
    }

    public function testRequiresSumUpToBeAddedToTheWorkspace(): void
    {
        self::actAsMemberOf('Sans SumUp');

        $this->expectExceptionObject(new ServiceNotAdded('SumUp'));
        $this->import();
    }

    public function testImportsProductsAndOrdersLinkedToEvents(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');

        $report = $this->import();

        self::assertSame(3, $report->productsCreated, 'Sticker Mousse, Tote bag, Print A4 (Fougère)');
        self::assertSame(2, $report->ordersImported);
        self::assertSame(0, $report->ordersAlreadyImported);

        $orders = self::getContainer()->get(ListOrdersHandler::class)();
        self::assertSame([['TFAKE0002'], ['TFAKE0001']], array_column($orders, 'externalReferences'));
        self::assertStringStartsWith('CMD-20300315-', $orders[0]->reference);
        self::assertSame(['sumup', 'sumup'], array_column($orders, 'source'));
        self::assertSame(['cash', 'card'], array_column($orders, 'paymentMethod'));
        self::assertSame(1_000, $orders[1]->total, 'amount charged by SumUp');
        self::assertSame(200, $orders[1]->discountTotal, 'SumUp discount kept');

        $products = self::getContainer()->get(ListProductsHandler::class)();
        $index = array_search('Sticker Mousse', array_column($products, 'name'), true);
        self::assertIsInt($index);
        $sticker = $products[$index];
        self::assertSame(400, $sticker->sellingPrice);
        self::assertSame(0, $sticker->buyingPrice);
        self::assertSame('MIS-STI', $sticker->reference);
        self::assertSame('Miscellaneous', $sticker->typeName);
        self::assertSame('Sticker Mousse', $sticker->displayName, 'the miscellaneous type does not prefix names');
    }

    public function testOrdersWithoutEventAreNotImportedAndReportedOnce(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');

        $report = $this->import();

        self::assertSame(2, $report->ordersWithoutEvent);
        self::assertSame(['2030-03-21', '2030-03-23'], $report->datesWithoutEvent);
        self::assertCount(2, self::getContainer()->get(ListOrdersHandler::class)());
    }

    public function testReimportDoesNotDuplicateAndPicksUpNewlyCoveredOrders(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $this->import();

        $this->scheduleEvent('Marché', '2030-03-21', '2030-03-21');
        $report = $this->import();

        self::assertSame(0, $report->productsCreated);
        self::assertSame(1, $report->ordersImported);
        self::assertSame(2, $report->ordersAlreadyImported);
        self::assertSame(['2030-03-23'], $report->datesWithoutEvent);
        self::assertCount(3, self::getContainer()->get(ListOrdersHandler::class)());
    }

    public function testLinesAreMatchedToExistingProductVariants(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::createProduct('Tote bag', 1_500, 500);
        self::createProduct('Print A4', 2_000, 600, ['Mousse', 'Fougère']);

        $report = $this->import();

        self::assertSame(1, $report->productsCreated, 'only Sticker Mousse is new');
        $orders = self::getContainer()->get(ListOrdersHandler::class)();
        self::assertSame(4, $orders[0]->itemCount, 'Tote bag, Sticker Mousse, Print A4 Fougère and a typed amount');
    }

    public function testImportedOrdersTakeTheirUnitsFromStockInChronologicalOrder(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $badge = (string) self::createProduct('Badge', 300, 50);
        self::getContainer()->get(RestockHandler::class)(new Restock($badge, null, 2, 100));
        self::getContainer()->get(RestockHandler::class)(new Restock($badge, null, 2, 400));
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-LATE', new \DateTimeImmutable('2030-03-14T15:00:00Z'), Money::cents(600), [ExternalSales::line('Badge', Money::cents(300), 2)]),
            ExternalSales::sumUp('TX-EARLY', new \DateTimeImmutable('2030-03-14T10:00:00Z'), Money::cents(600), [ExternalSales::line('Badge', Money::cents(300), 2)]),
        ]);

        $this->import();
        self::getContainer()->get('doctrine')->getManager()->clear();

        $orders = self::getContainer()->get(ListOrdersHandler::class)();
        $costs = [];
        foreach ($orders as $order) {
            $costs[$order->externalReferences[0]] = self::getContainer()->get(GetOrderHandler::class)($order->id)->costOfGoods;
        }
        self::assertSame(['TX-LATE' => 400, 'TX-EARLY' => 100], $costs);
        $product = array_values(array_filter(self::getContainer()->get(ListProductsHandler::class)(), static fn ($view): bool => $view->id === $badge))[0];
        self::assertSame(0, $product->onHand);
    }

    public function testSalesAreListedAtThePriceOfTheChannelLinkedToTheService(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $channel = (string) self::getContainer()->get(CreateChannelHandler::class)('Stand', ChannelKind::Market, 'sumup');
        $badge = self::createProduct('Badge', 300, 50);
        self::createProduct('Aimant', 500, 50);
        self::getContainer()->get(UpdateProductHandler::class)(new UpdateProduct($badge, 'Badge', 300, [], channelPrices: [$channel => 250]));
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-CHANNEL', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(900), [
                ExternalSales::line('Badge', Money::cents(250), 2),
                ExternalSales::line('Aimant', Money::cents(400), 1),
            ]),
        ]);

        $this->import();

        $order = self::getContainer()->get(GetOrderHandler::class)(self::getContainer()->get(ListOrdersHandler::class)()[0]->id);
        self::assertSame([250, 500], array_column($order->lines, 'unitPrice'));
        self::assertSame(100, $order->discountTotal, 'only the magnet was sold below its price');
    }

    public function testSalesAreListedAtThePriceOfTheirDay(): void
    {
        $this->scheduleEvent('Japan Expo', '2026-07-09', '2026-07-12');
        $badge = self::createProduct('Badge', 300, 50);
        self::getContainer()->get(RecordSellingPriceHandler::class)($badge, 250, '2026-07-01');
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-JULY', new \DateTimeImmutable('2026-07-10T12:00:00Z'), Money::cents(250), [ExternalSales::line('Badge', Money::cents(250), 1)]),
        ]);

        $this->import();

        $order = self::getContainer()->get(GetOrderHandler::class)(self::getContainer()->get(ListOrdersHandler::class)()[0]->id);
        self::assertSame([250], array_column($order->lines, 'unitPrice'));
        self::assertSame(0, $order->discountTotal, 'the July price was paid: no discount');
    }

    public function testProductWithVariantsButNoVariantInSumUpWaitsToBeLinked(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::createProduct('T-shirt', 2_000, 0, ['S', 'M']);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-TS', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(2_000), [ExternalSales::line('T-shirt', Money::cents(2_000), 1)]),
        ]);

        $report = $this->import();

        self::assertSame(0, $report->ordersImported);
        self::assertSame(1, $report->ordersWaitingForItems);
        self::assertSame(1, $report->itemsToLink);
    }

    public function testTheFeeSumUpReportedIsChargedOnceKnown(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::getContainer()->get(CreateChannelHandler::class)('Stand', ChannelKind::Market, 'sumup');
        self::createProduct('Badge', 1_000, 50);
        $sale = static fn (string $code, ?int $fee): ExternalSale => ExternalSales::sumUp($code, new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(1_000), [ExternalSales::line('Badge', Money::cents(1_000), 1)], PaymentMethod::Card, null === $fee ? null : Money::cents($fee));
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([$sale('TX-PAID', 17), $sale('TX-PENDING', null)]);

        self::assertSame(0, $this->import()->feesUpdated);

        $charges = static fn (string $code): array => self::getContainer()->get(GetOrderHandler::class)(self::ordersBySale()[$code]->id)->charges;
        self::assertSame([['label' => 'SumUp fees', 'amount' => 17]], $charges('TX-PAID'));
        self::assertSame([], $charges('TX-PENDING'), 'no fee until SumUp reports it');

        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([$sale('TX-PAID', 17), $sale('TX-PENDING', 19)]);
        self::assertSame(1, $this->import()->feesUpdated);
        self::assertSame([['label' => 'SumUp fees', 'amount' => 19]], $charges('TX-PENDING'));
        self::assertSame(0, $this->import()->feesUpdated);
    }

    public function testADiscountRuleMatchingSumUpsDiscountReplacesIt(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $print = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Print')->id();
        $sticker = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Sticker')->id();
        self::createProduct('Forêt', 1_200, typeId: $print);
        self::createProduct('Mousse', 600, typeId: $sticker);
        $rule = (string) self::getContainer()->get(CreateDiscountRuleHandler::class)(
            DiscountRules::fixedPrice('2 prints et 1 sticker pour 18 €', 1_800, DiscountRules::type($print, 2), DiscountRules::type($sticker, 1)),
        );
        $lines = [ExternalSales::line('Print Forêt', Money::cents(900), 2), ExternalSales::line('Sticker Mousse', Money::cents(450), 1)];
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-RULE', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(1_800), $lines),
            ExternalSales::sumUp('TX-ROUNDED', new \DateTimeImmutable('2030-03-14T13:00:00Z'), Money::cents(1_801), $lines),
            ExternalSales::sumUp('TX-OTHER', new \DateTimeImmutable('2030-03-14T14:00:00Z'), Money::cents(1_750), $lines),
        ]);

        $this->import();

        $orders = array_map(static fn (OrderSummaryView $order): string => $order->id, self::ordersBySale());
        $discounts = static fn (string $reference): array => self::getContainer()->get(GetOrderHandler::class)($orders[$reference])->discounts;
        self::assertSame([['label' => '2 prints et 1 sticker pour 18 €', 'amount' => 1_200, 'ruleId' => $rule]], $discounts('TX-RULE'));
        self::assertSame([['label' => '2 prints et 1 sticker pour 18 €', 'amount' => 1_200, 'ruleId' => $rule]], $discounts('TX-ROUNDED'));
        self::assertSame(1_800, self::getContainer()->get(GetOrderHandler::class)($orders['TX-ROUNDED'])->total);
        self::assertSame([['label' => 'SumUp discount', 'amount' => 1_250, 'ruleId' => null]], $discounts('TX-OTHER'));
    }

    public function testCategoryBecomesTheProductType(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-CAT', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(3_000), [
                ExternalSales::line('Print Forêt', Money::cents(1_500), 1, 'Print'),
                ExternalSales::line('Rivière', Money::cents(1_500), 1, 'print'),
            ]),
        ]);

        $report = $this->import();

        self::assertSame(1, $report->typesCreated);
        $products = self::getContainer()->get(ListProductsHandler::class)();
        self::assertSame(['Print Forêt', 'Print Rivière'], array_column($products, 'displayName'));
        self::assertSame(['Forêt', 'Rivière'], array_column($products, 'name'));
        self::assertSame(['PRI-FOR', 'PRI-RIV'], array_column($products, 'reference'));
    }

    public function testLinesWithoutNameAreImportedAsUnknownProductsAtTheirOwnPrice(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-FREE1', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(700), [ExternalSales::line('', Money::cents(700), 1)]),
            ExternalSales::sumUp('TX-FREE2', new \DateTimeImmutable('2030-03-14T13:00:00Z'), Money::cents(1_200), [ExternalSales::line(' ', Money::cents(1_000), 1, 'Print'), ExternalSales::line('', Money::cents(200), 1)]),
        ]);

        $report = $this->import();

        self::assertSame(2, $report->ordersImported);
        self::assertSame(0, $report->productsCreated);
        self::assertSame([], self::getContainer()->get(ListProductsHandler::class)());
        $orders = self::ordersBySale();
        self::assertSame([700, 1_200], [$orders['TX-FREE1']->total, $orders['TX-FREE2']->total]);
        $lines = self::getContainer()->get(GetOrderHandler::class)($orders['TX-FREE2']->id)->lines;
        self::assertEqualsCanonicalizing([[null, 'Unknown product', 1_000], [null, 'Unknown product', 200]], array_map(static fn (array $line): array => [$line['productId'], $line['label'], $line['unitPrice']], $lines));
    }

    public function testAnUnknownProductLineIsLinkedLaterAndTakesStock(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $print = self::createProduct('Print', 1_500, 0, ['A4', 'A3']);
        self::getContainer()->get(RestockHandler::class)(new Restock($print, 'A3', 5, 2_000));
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-FREE', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(2_400), [ExternalSales::line('', Money::cents(1_200), 2)]),
        ]);
        $this->import();
        $orderId = self::getContainer()->get(ListOrdersHandler::class)()[0]->id;
        $lineId = self::getContainer()->get(GetOrderHandler::class)($orderId)->lines[0]['id'];

        self::getContainer()->get(IdentifyOrderLineHandler::class)(new IdentifyOrderLine($orderId, $lineId, $print, 'a3'));
        self::getContainer()->get('doctrine')->getManager()->clear();

        $order = self::getContainer()->get(GetOrderHandler::class)($orderId);
        self::assertSame([$print, 'Print — A3', 1_200, 800], [$order->lines[0]['productId'], $order->lines[0]['label'], $order->lines[0]['unitPrice'], $order->lines[0]['cost']]);
        self::assertSame(2_400, $order->total);
        $stock = array_column(self::getContainer()->get(ListProductsHandler::class)()[0]->stock, 'onHand', 'variant');
        self::assertSame(3, $stock['A3']);

        $this->expectException(LineAlreadyIdentified::class);
        self::getContainer()->get(IdentifyOrderLineHandler::class)(new IdentifyOrderLine($orderId, $lineId, $print, 'A4'));
    }

    public function testAnAmountTypedForSeveralItemsIsSplitIntoTheirProductsWithTheDiscountThatExplainsThePrice(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $print = self::createProduct('Print', 600);
        $keychain = self::createProduct('Keychain', 1_200);
        self::getContainer()->get(RestockHandler::class)(new Restock($keychain, null, 5, 300));
        self::getContainer()->get(CreateDiscountRuleHandler::class)(DiscountRules::fixedPrice('Print et porte-clés pour 15 €', 1_500, DiscountRules::product($print, 1), DiscountRules::product($keychain, 1)));
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-FREE', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(2_500), [ExternalSales::line('', Money::cents(1_500), 1), ExternalSales::line('', Money::cents(1_000), 1)]),
        ]);
        $this->import();
        $orderId = self::getContainer()->get(ListOrdersHandler::class)()[0]->id;
        $lines = self::getContainer()->get(GetOrderHandler::class)($orderId)->lines;

        $split = self::getContainer()->get(SplitOrderLineHandler::class);
        $split(new SplitOrderLine($orderId, $lines[0]['id'], [new RequestedLine($print, null, 1), new RequestedLine($keychain, null, 1)]));
        $split(new SplitOrderLine($orderId, $lines[1]['id'], [new RequestedLine($keychain, null, 1)]));
        self::getContainer()->get('doctrine')->getManager()->clear();

        $order = self::getContainer()->get(GetOrderHandler::class)($orderId);
        self::assertSame([['Print', 1, 600], ['Keychain', 2, 1_200]], array_map(static fn (array $line): array => [$line['label'], $line['quantity'], $line['unitPrice']], $order->lines));
        self::assertSame([['Print et porte-clés pour 15 €', 300], ['SumUp discount', 200]], array_map(static fn (array $discount): array => [$discount['label'], $discount['amount']], $order->discounts));
        self::assertSame(2_500, $order->total);
        self::assertSame(3, array_column(self::getContainer()->get(ListProductsHandler::class)(), 'stock', 'name')['Keychain'][0]['onHand']);
    }

    public function testTwoSalesMergedIntoOneOrderAreNotImportedAgain(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::createProduct('Zine', 1_000);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-CARD', new \DateTimeImmutable('2030-03-14T12:01:00Z'), Money::cents(1_000), [ExternalSales::line('Zine', Money::cents(1_000), 1)]),
            ExternalSales::sumUp('TX-CASH', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(1_000), [ExternalSales::line('Zine', Money::cents(1_000), 1)]),
        ]);
        $this->import();
        $orders = self::ordersBySale();

        self::getContainer()->get(MergeOrdersHandler::class)($orders['TX-CARD']->id, $orders['TX-CASH']->id);
        $report = $this->import();

        self::assertSame([0, 2], [$report->ordersImported, $report->ordersAlreadyImported]);
        $merged = self::getContainer()->get(ListOrdersHandler::class)();
        self::assertCount(1, $merged);
        self::assertSame([$orders['TX-CARD']->reference, ['TX-CASH', 'TX-CARD'], 2, 2_000], [$merged[0]->reference, $merged[0]->externalReferences, $merged[0]->itemCount, $merged[0]->total]);
    }

    public function testDiscountedLinesNeitherLowerTheProductPriceNorTheOrderTotal(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::createProduct('Mousse', 283);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-BUNDLE', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(800), [
                ExternalSales::line('Calcifer', Money::cents(267), 1),
                ExternalSales::line('Lichen', Money::cents(267), 1),
                ExternalSales::line('Fougère', Money::cents(266), 1),
            ]),
            ExternalSales::sumUp('TX-SINGLE', new \DateTimeImmutable('2030-03-14T13:00:00Z'), Money::cents(300), [ExternalSales::line('Calcifer', Money::cents(300), 1)]),
            ExternalSales::sumUp('TX-MOUSSE', new \DateTimeImmutable('2030-03-14T14:00:00Z'), Money::cents(300), [ExternalSales::line('Mousse', Money::cents(300), 1)]),
        ]);

        $this->import();

        $prices = array_column(self::getContainer()->get(ListProductsHandler::class)(), 'sellingPrice', 'name');
        self::assertSame(300, $prices['Calcifer'], 'highest price SumUp charged');
        self::assertSame(283, $prices['Mousse'], 'existing products are not modified');
        $orders = self::ordersBySale();
        self::assertSame(300, $orders['TX-SINGLE']->total);
        self::assertSame(300, $orders['TX-MOUSSE']->total, 'charged more than the catalogue price');
        self::assertSame(800, $orders['TX-BUNDLE']->total);
    }

    public function testDescriptionIsTheVariant(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::createProduct('Zine', 1_000);
        self::createProduct('T-shirt', 2_000, 0, ['S', 'M']);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-VARIANTS', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(10_500), [
                ExternalSales::line('Forêt', Money::cents(1_500), 1, variant: 'A4'),
                ExternalSales::line('Forêt', Money::cents(2_000), 1, variant: 'A3'),
                ExternalSales::line('T-shirt', Money::cents(2_000), 1, variant: 'm'),
                ExternalSales::line('T-shirt', Money::cents(2_000), 1, variant: 'XL'),
                ExternalSales::line('T-shirt S', Money::cents(2_000), 1),
                ExternalSales::line('Zine', Money::cents(1_000), 1, variant: 'Prix normal'),
            ]),
        ]);

        $report = $this->import();

        self::assertSame(1, $report->ordersImported);
        self::assertSame(1, $report->productsCreated);
        $variants = array_column(self::getContainer()->get(ListProductsHandler::class)(), 'variants', 'name');
        self::assertSame(['A4', 'A3'], $variants['Forêt']);
        self::assertSame(['S', 'M', 'XL'], $variants['T-shirt']);
        self::assertSame([], $variants['Zine']);
    }

    public function testProductsSplitPerVariantAreRecognised(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::createProduct('Mug Lichen', 1_200);
        self::createProduct('Mug (Fougère)', 1_200);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-SPLIT', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(2_400), [
                ExternalSales::line('Mug', Money::cents(1_200), 1, variant: 'Lichen'),
                ExternalSales::line('Mug', Money::cents(1_200), 1, variant: 'Fougère'),
            ]),
        ]);

        $report = $this->import();

        self::assertSame(1, $report->ordersImported);
        self::assertSame(0, $report->productsCreated);
        self::assertSame([[], []], array_column(self::getContainer()->get(ListProductsHandler::class)(), 'variants'));
    }

    public function testProductsCreatedInACategoryAreMatchedByTheNextImport(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $gateway = self::getContainer()->get(FakeSumUpGateway::class);
        $sale = static fn (string $code, string $at): ExternalSale => ExternalSales::sumUp($code, new \DateTimeImmutable($at), Money::cents(3_000), [
            ExternalSales::line('Print Forêt', Money::cents(1_500), 1, 'Print'),
            ExternalSales::line('Rivière', Money::cents(1_500), 1, 'print'),
        ]);
        $gateway->willReturn([$sale('TX-1', '2030-03-14T12:00:00Z')]);
        $this->import();

        $gateway->willReturn([$sale('TX-1', '2030-03-14T12:00:00Z'), $sale('TX-2', '2030-03-14T13:00:00Z')]);
        $report = $this->import();

        self::assertSame(0, $report->productsCreated);
        self::assertSame(['Print Forêt', 'Print Rivière'], array_column(self::getContainer()->get(ListProductsHandler::class)(), 'displayName'));
    }

    public function testAProductOfATypePrefixingNamesIsMatchedByItsOwnNameAndSoleVariant(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $sticker = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Sticker', variants: ['mat'])->id();
        self::createProduct('Black kitties Vase Sticker', 300, 0, ['mat'], $sticker);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-BK', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(300), [ExternalSales::line('Black kitties Vase Sticker', Money::cents(300), 1)]),
        ]);

        $report = $this->import();

        self::assertSame([1, 0], [$report->ordersImported, $report->productsCreated]);
        $order = self::getContainer()->get(ListOrdersHandler::class)()[0];
        self::assertSame('Sticker Black kitties Vase Sticker — mat', self::getContainer()->get(GetOrderHandler::class)($order->id)->lines[0]['label']);
    }

    public function testAnOwnNameSharedByProductsOfSeveralTypesIsNotGuessed(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $types = self::getContainer()->get(CreateProductTypeHandler::class);
        self::createProduct('Mousse', 300, typeId: (string) $types('Sticker')->id());
        self::createProduct('Mousse', 1_500, typeId: (string) $types('Print')->id());
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-MO', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(300), [ExternalSales::line('Mousse', Money::cents(300), 1)]),
        ]);

        self::assertSame(1, $this->import()->productsCreated);
    }

    public function testARenamedProductKeepsReceivingItsSumUpSales(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $sticker = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Sticker', variants: ['mat'])->id();
        $product = self::createProduct('Black kitties Vase Sticker', 300, 0, ['mat'], $sticker);
        $this->sellZines('Black kitties Vase Sticker', 'TX-1');
        $this->import();

        self::getContainer()->get(UpdateProductHandler::class)(new UpdateProduct($product, 'Chat noir au vase', 300, ['mat'], $sticker));
        $this->sellZines('Black kitties Vase Sticker', 'TX-1', 'TX-2');
        $report = $this->import();

        self::assertSame([1, 0], [$report->ordersImported, $report->productsCreated]);
        self::assertSame(['Sticker Chat noir au vase'], array_column(self::getContainer()->get(ListProductsHandler::class)(), 'displayName'));
    }

    public function testMovingAnImportedProductTakesItsSumUpItemAlong(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $fanzine = self::createProduct('Fanzine', 1_000);
        $this->sellZines('Zine', 'TX-1');
        $this->import();

        self::getContainer()->get(MoveVariantHandler::class)(new MoveVariant($this->productNamed('Zine'), null, $fanzine, null, null));
        $this->sellZines('Zine', 'TX-1', 'TX-2');
        $report = $this->import();

        self::assertSame([1, 0], [$report->ordersImported, $report->productsCreated]);
        self::assertSame(['Fanzine'], array_column(self::getContainer()->get(ListProductsHandler::class)(), 'name'));
    }

    public function testAnItemLinkedToADeletedProductIsResolvedAgain(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $this->sellZines('Zine', 'TX-1');
        $this->import();

        self::getContainer()->get(DeleteProductHandler::class)($this->productNamed('Zine'));
        self::getContainer()->get('doctrine')->getManager()->clear();
        $this->sellZines('Zine', 'TX-1', 'TX-2');
        $report = $this->import();

        self::assertSame([1, 1, 0], [$report->ordersImported, $report->productsCreated, $report->itemsToLink]);
    }

    public function testTypedProductsAreMatchedByDisplayName(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        $sticker = (string) self::getContainer()->get(CreateProductTypeHandler::class)('Sticker')->id();
        self::createProduct('Mousse', 400, 60, typeId: $sticker);

        $report = $this->import();

        self::assertSame(2, $report->productsCreated, '"Sticker Mousse" is the existing typed product');
    }

    public function testProductsWithoutCategoryJoinTheMiscellaneousTypeCreatedOnce(): void
    {
        $this->scheduleEvent('Salon de printemps', '2030-03-14', '2030-03-15');
        self::createProduct('Badge', 300);

        $this->import();

        $types = array_map(static fn ($type): array => [$type->name, $type->code, $type->prefixesNames], self::getContainer()->get(ListProductTypesHandler::class)());
        self::assertSame([['Miscellaneous', 'MIS', false], ['Print', 'PRI', true]], $types);
        self::assertSame(['Badge' => 'Miscellaneous', 'Sticker Mousse' => 'Miscellaneous', 'Tote bag' => 'Miscellaneous', 'A4 (Fougère)' => 'Print'], array_column(self::getContainer()->get(ListProductsHandler::class)(), 'typeName', 'name'));
    }

    /**
     * @return array<string, OrderSummaryView>
     */
    private static function ordersBySale(): array
    {
        $orders = [];
        foreach (self::getContainer()->get(ListOrdersHandler::class)() as $order) {
            $orders[$order->externalReferences[0]] = $order;
        }

        return $orders;
    }

    private function sellZines(string $name, string ...$codes): void
    {
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn(array_values(array_map(
            static fn (string $code): ExternalSale => ExternalSales::sumUp($code, new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(1_000), [ExternalSales::line($name, Money::cents(1_000), 1)]),
            $codes,
        )));
    }

    private function productNamed(string $name): string
    {
        foreach (self::getContainer()->get(ListProductsHandler::class)() as $product) {
            if ($product->name === $name) {
                return $product->id;
            }
        }
        self::fail("no product named $name");
    }

    private function import(): ImportReport
    {
        $report = self::getContainer()->get(ImportSalesHandler::class)('sumup');
        self::getContainer()->get('doctrine')->getManager()->clear();

        return $report;
    }

    private function scheduleEvent(string $name, string $start, string $end): void
    {
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent($name, 'Lyon', new \DateTimeImmutable($start), new \DateTimeImmutable($end)));
    }
}
