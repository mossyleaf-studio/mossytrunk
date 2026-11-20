<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\ImportSales\ImportReport;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Integration\ListExternalItems\ListExternalItemsHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\GetOrder\OrderView;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Order\ListOrders\OrderSummaryView;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Product\ListProducts\ListProductsHandler;
use App\Application\Sales\AddChannelCost\AddChannelCostHandler;
use App\Application\Sales\CreateChannel\CreateChannelHandler;
use App\Application\Stock\GetProductStock\GetProductStockHandler;
use App\Domain\Order\OrderRepository;
use App\Domain\Sales\ChannelCostKind;
use App\Domain\Sales\ChannelKind;
use App\Infrastructure\Connector\Website\FakeWebsiteGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\ExternalSales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class ImportWebsiteSalesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private const string PAID = 'ML-7K2P-9QXA';
    private const string CANCELLED = 'ML-3D8H-4TNC';

    private string $sticker;
    private string $print;

    protected function setUp(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        self::actAsMemberOf();
        $types = self::getContainer()->get(CreateProductTypeHandler::class);
        $this->sticker = self::createProduct('Mousse', 400, 60, typeId: (string) $types('Sticker')->id(), reference: 'WEB-STICKER');
        $this->print = self::createProduct('Forêt', 1_800, 300, ['A4', 'A3'], (string) $types('Print')->id(), reference: 'WEB-PRINT');
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'website', ['address' => 'https://shop.example', 'token' => 'integration-token-0123456789abcdef']);
    }

    public function testTheSkuPicksTheProductAndItsVariantAndShippingIsPartOfTheTotal(): void
    {
        $report = $this->import();

        self::assertSame(['website', 'Website', 2, 0, 0, 0], [$report->service, $report->label, $report->ordersImported, $report->ordersWaitingForItems, $report->itemsToLink, $report->productsCreated]);
        $order = $this->order(self::PAID);
        self::assertSame(['website', 'Website', null, 'card'], [$order->source, $order->sourceLabel, $order->event, $order->paymentMethod]);
        self::assertSame(
            [[$this->sticker, 'Sticker Mousse', 2, 400], [$this->print, 'Print Forêt — A4', 1, 1_800]],
            array_map(static fn (array $line): array => [$line['productId'], $line['label'], $line['quantity'], $line['unitPrice']], $order->lines),
        );
        self::assertSame([2_600, 0, 450, 3_050], [$order->subtotal, $order->discountTotal, $order->shipping, $order->total]);
        self::assertSame([], $order->charges, 'the site reports no fee');
        self::assertSame([['A4', -1]], $this->stockOf($this->print));
    }

    public function testACancelledOrderGivesItsUnitsBack(): void
    {
        $this->import();

        $cancelled = $this->summary(self::CANCELLED);
        self::assertSame([true, true, 650], [$cancelled->cancelled, null !== $cancelled->refundedAt, $cancelled->total]);
        self::assertSame([[null, -2]], $this->stockOf($this->sticker), 'only the two stickers of the paid order are gone');
    }

    public function testAnUnknownSkuWaitsToBeLinkedAndCreatesNoProduct(): void
    {
        $products = \count(self::getContainer()->get(ListProductsHandler::class)());
        self::getContainer()->get(FakeWebsiteGateway::class)->willReturn([
            ExternalSales::website('ML-0000-0001', new \DateTimeImmutable('2030-02-06T10:00:00+01:00'), [ExternalSales::listing('WEB-GONE', 'Carnet Lichen', 1_200, sku: 'WEB-GONE')]),
        ]);

        $report = $this->import();

        self::assertSame([0, 1, 1, 0], [$report->ordersImported, $report->ordersWaitingForItems, $report->itemsToLink, $report->productsCreated]);
        $item = self::getContainer()->get(ListExternalItemsHandler::class)('website')[0];
        self::assertSame(['WEB-GONE', 'Carnet Lichen', []], [$item->externalRef, $item->label, $item->components]);
        self::assertCount($products, self::getContainer()->get(ListProductsHandler::class)());
    }

    public function testTheCostOfTheLinkedOnlineChannelIsTheOnlyChargeOnTheOrder(): void
    {
        $channel = (string) self::getContainer()->get(CreateChannelHandler::class)('Boutique du site', ChannelKind::Online, 'website');
        self::getContainer()->get(AddChannelCostHandler::class)($channel, 'Online payment', ChannelCostKind::Percent, 250);

        $this->import();

        $order = $this->order(self::PAID);
        self::assertSame('Boutique du site', $order->channelName);
        self::assertSame([['label' => 'Online payment', 'amount' => 76]], $order->charges);
    }

    public function testASecondImportSkipsEverything(): void
    {
        $this->import();

        $second = $this->import();

        self::assertSame([0, 2, 0, 0], [$second->ordersImported, $second->ordersAlreadyImported, $second->feesUpdated, $second->ordersCancelled]);
        self::assertSame([], self::getContainer()->get(OrderRepository::class)->awaitingSaleFees('website'), 'a zero fee is a known fee: nothing is read again');
        self::assertCount(2, $this->imported());
    }

    private function import(): ImportReport
    {
        return self::getContainer()->get(ImportSalesHandler::class)('website');
    }

    /**
     * @return list<OrderSummaryView>
     */
    private function imported(): array
    {
        self::getContainer()->get('doctrine')->getManager()->clear();

        return array_values(array_filter(self::getContainer()->get(ListOrdersHandler::class)(), static fn (OrderSummaryView $order): bool => 'website' === $order->source));
    }

    private function summary(string $reference): OrderSummaryView
    {
        return array_values(array_filter($this->imported(), static fn (OrderSummaryView $order): bool => [$reference] === $order->externalReferences))[0];
    }

    private function order(string $reference): OrderView
    {
        return self::getContainer()->get(GetOrderHandler::class)($this->summary($reference)->id);
    }

    /**
     * @return list<array{?string, int}>
     */
    private function stockOf(string $productId): array
    {
        return array_values(array_map(
            static fn ($stock): array => [$stock->variant, $stock->onHand],
            array_filter(self::getContainer()->get(GetProductStockHandler::class)($productId), static fn ($stock): bool => 0 !== $stock->onHand),
        ));
    }
}
