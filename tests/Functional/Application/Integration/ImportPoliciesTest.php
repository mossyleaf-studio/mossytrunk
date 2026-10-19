<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Integration\CompleteAuthorization\CompleteAuthorizationHandler;
use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\ImportSales\ImportReport;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Integration\LinkExternalItem\LinkExternalItemHandler;
use App\Application\Integration\ListExternalItems\ListExternalItemsHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\ListProducts\ListProductsHandler;
use App\Domain\Integration\SalesContext;
use App\Domain\Integration\UnknownItems;
use App\Domain\Shared\Money;
use App\Infrastructure\Connector\Etsy\FakeEtsyGateway;
use App\Infrastructure\Connector\SumUp\FakeSumUpGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\ExternalSales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class ImportPoliciesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    protected function setUp(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        self::actAsMemberOf();
    }

    public function testOnlineSumUpSalesHaveNoEventAndKeepSumUpsDiscount(): void
    {
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'sumup', ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test'], SalesContext::Online);
        self::createProduct('Mousse', 400);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-ONLINE', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(1_000), [ExternalSales::line('Mousse', Money::cents(400), 3)]),
        ]);

        $report = $this->import('sumup');

        self::assertSame([1, 0], [$report->ordersImported, $report->ordersWithoutEvent]);
        $order = self::getContainer()->get(ListOrdersHandler::class)()[0];
        self::assertNull($order->eventId);
        self::assertSame([['label' => 'SumUp discount', 'amount' => 200, 'ruleId' => null]], self::getContainer()->get(GetOrderHandler::class)($order->id)->discounts);
    }

    public function testSumUpLinesCanWaitToBeLinkedByHandInsteadOfCreatingProducts(): void
    {
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'sumup', ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test'], unknownItems: UnknownItems::LinkByHand);
        $this->scheduleEvent('2030-03-14', '2030-03-15');
        $moss = self::createProduct('Sticker mousse', 400);
        self::getContainer()->get(FakeSumUpGateway::class)->willReturn([
            ExternalSales::sumUp('TX-MOSS', new \DateTimeImmutable('2030-03-14T12:00:00Z'), Money::cents(400), [ExternalSales::line('Stk Mousse', Money::cents(400))]),
        ]);

        $first = $this->import('sumup');

        self::assertSame([0, 0, 1, 1], [$first->ordersImported, $first->productsCreated, $first->ordersWaitingForItems, $first->itemsToLink]);
        $item = self::getContainer()->get(ListExternalItemsHandler::class)('sumup')[0];
        self::assertSame('Stk Mousse', $item->label);
        self::getContainer()->get(LinkExternalItemHandler::class)('sumup', $item->id, [new RequestedLine($moss, null, 1)]);

        $second = $this->import('sumup');

        self::assertSame([1, 0], [$second->ordersImported, $second->itemsToLink]);
        self::assertCount(1, self::getContainer()->get(ListProductsHandler::class)());
    }

    public function testEtsySalesCanCreateTheMissingProductsAtTheEtsyPrice(): void
    {
        $this->connectEtsy(unknownItems: UnknownItems::CreateProduct);
        self::getContainer()->get(FakeEtsyGateway::class)->willReturn([
            ExternalSales::etsy('9001', new \DateTimeImmutable('2030-01-01T10:00:00Z'), [ExternalSales::listing('77', 'Carnet lichen', 1_250, 2)]),
        ]);

        $report = $this->import('etsy');

        self::assertSame([1, 1], [$report->ordersImported, $report->productsCreated]);
        self::assertSame([['Carnet lichen', 1_250]], array_map(static fn ($product): array => [$product->name, $product->sellingPrice], self::getContainer()->get(ListProductsHandler::class)()));
    }

    public function testEtsySalesAtAnEventNeedTheEventToExist(): void
    {
        $this->connectEtsy(SalesContext::AtEvent);
        self::createProduct('Carnet', 1_250);
        self::getContainer()->get(FakeEtsyGateway::class)->willReturn([
            ExternalSales::etsy('9002', new \DateTimeImmutable('2030-01-01T10:00:00Z'), [ExternalSales::listing('78', 'Carnet', 1_250)]),
        ]);

        $report = $this->import('etsy');

        self::assertSame([0, 1, ['2030-01-01']], [$report->ordersImported, $report->ordersWithoutEvent, $report->datesWithoutEvent]);
    }

    public function testEtsyMatchesTheSkuBeforeTheTitle(): void
    {
        $this->connectEtsy();
        self::createProduct('Carnet', 1_250);
        $reference = self::getContainer()->get(ListProductsHandler::class)()[0]->reference;
        self::getContainer()->get(FakeEtsyGateway::class)->willReturn([
            ExternalSales::etsy('9003', new \DateTimeImmutable('2030-01-01T10:00:00Z'), [ExternalSales::listing('79', 'Joli carnet fait main', 1_300, sku: $reference)]),
        ]);

        self::assertSame(1, $this->import('etsy')->ordersImported);
        self::assertSame(1_300, self::getContainer()->get(ListOrdersHandler::class)()[0]->total, 'sold at the Etsy price');
    }

    private function connectEtsy(?SalesContext $salesContext = null, ?UnknownItems $unknownItems = null): void
    {
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'etsy', ['keystring' => 'keystring123', 'shared_secret' => 'shared-secret'], $salesContext, $unknownItems);
        self::getContainer()->get(CompleteAuthorizationHandler::class)('etsy', 'code', 'verifier', 'https://app.test/settings/etsy/callback');
    }

    private function import(string $service): ImportReport
    {
        $report = self::getContainer()->get(ImportSalesHandler::class)($service);
        self::getContainer()->get('doctrine')->getManager()->clear();

        return $report;
    }

    private function scheduleEvent(string $start, string $end): void
    {
        self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable($start), new \DateTimeImmutable($end)));
    }
}
