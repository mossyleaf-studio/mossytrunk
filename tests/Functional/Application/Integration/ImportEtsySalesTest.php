<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Integration;

use App\Application\Accounting\ExportOrders\ExportOrdersHandler;
use App\Application\Integration\CompleteAuthorization\CompleteAuthorizationHandler;
use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\ConnectionSession;
use App\Application\Integration\DisconnectService\DisconnectServiceHandler;
use App\Application\Integration\Exception\ServiceNotConnected;
use App\Application\Integration\ExternalSale;
use App\Application\Integration\ImportSales\ImportSalesHandler;
use App\Application\Integration\LinkExternalItem\LinkExternalItemHandler;
use App\Application\Integration\ListExternalItems\ListExternalItemsHandler;
use App\Application\Integration\ListServices\ListServicesHandler;
use App\Application\Integration\ListServices\ServiceView;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Stock\GetProductStock\GetProductStockHandler;
use App\Domain\Integration\ServiceConnectionRepository;
use App\Infrastructure\Connector\Etsy\FakeEtsyGateway;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use App\Tests\Support\ExternalSales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class ImportEtsySalesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private const string CALLBACK = 'https://app.test/settings/etsy/callback';

    private string $sticker;
    private string $print;

    protected function setUp(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        self::actAsMemberOf();
        $types = self::getContainer()->get(CreateProductTypeHandler::class);
        $this->sticker = self::createProduct('Mousse', 400, 60, typeId: (string) $types('Sticker')->id());
        $this->print = self::createProduct('Forêt', 1_800, 300, ['A4', 'A3'], (string) $types('Print')->id());
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'etsy', ['keystring' => 'keystring123', 'shared_secret' => 'shared-secret']);
    }

    public function testAuthorizingStoresTheShopAndDisconnectingForgetsIt(): void
    {
        self::assertSame('Atelier Mousse sur Etsy', $this->authorize());
        self::assertSame([true, 'Atelier Mousse sur Etsy'], [$this->etsy()->connection?->authorized, $this->etsy()->connection?->accountName]);

        self::getContainer()->get(DisconnectServiceHandler::class)('etsy');

        self::assertFalse($this->etsy()->connection?->authorized);
    }

    public function testImportIsRefusedUntilTheShopIsAuthorized(): void
    {
        $this->expectExceptionObject(new ServiceNotConnected('Etsy'));

        $this->import();
    }

    public function testReceiptsWithAnUnknownListingWaitUntilItIsLinked(): void
    {
        $this->authorize();

        $first = $this->import();
        self::assertSame([1, 0, 1, 1], [$first->ordersImported, $first->ordersAlreadyImported, $first->ordersWaitingForItems, $first->itemsToLink]);

        $item = self::getContainer()->get(ListExternalItemsHandler::class)('etsy')[0];
        self::assertSame(['A4', []], [$item->variation, $item->components]);
        self::getContainer()->get(LinkExternalItemHandler::class)('etsy', $item->id, [new RequestedLine($this->print, 'A4', 1)]);

        $second = $this->import();
        self::assertSame([1, 1, 0, 0], [$second->ordersImported, $second->ordersAlreadyImported, $second->ordersWaitingForItems, $second->itemsToLink]);
        self::getContainer()->get('doctrine')->getManager()->clear();

        $orders = self::getContainer()->get(ListOrdersHandler::class)();
        $receipt = array_values(array_filter($orders, static fn ($order): bool => ['ETSY-3100000001'] === $order->externalReferences))[0];
        self::assertSame(['etsy', 'Etsy', null], [$receipt->source, $receipt->sourceLabel, $receipt->eventName]);
        $order = self::getContainer()->get(GetOrderHandler::class)($receipt->id);
        self::assertSame([2_600, 100, 250, 2_750], [$order->subtotal, $order->discountTotal, $order->shipping, $order->total]);
        self::assertSame('Etsy discount', $order->discounts[0]['label']);
        self::assertSame([['label' => 'Etsy fees', 'amount' => 351]], $order->charges);
        $unpaid = array_values(array_filter($orders, static fn ($order): bool => ['ETSY-3100000002'] === $order->externalReferences))[0];
        self::assertSame([], self::getContainer()->get(GetOrderHandler::class)($unpaid->id)->charges, 'no fee until Etsy posts the payment');
        self::assertNull($order->event);
        self::assertSame(-3, self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0]->onHand);

        $csv = self::getContainer()->get(ExportOrdersHandler::class)('2030-01-01', '2030-01-02')->content;
        self::assertStringContainsString(';Etsy;;Card;3;', $csv);
        self::assertStringContainsString(';26,00;1,00;2,50;27,50;', $csv);
    }

    public function testAnItemCannotBeLinkedThroughAnotherService(): void
    {
        $this->authorize();
        $this->import();
        $item = self::getContainer()->get(ListExternalItemsHandler::class)('etsy')[0];

        $this->expectException(\App\Domain\Shared\Exception\NotFound::class);

        self::getContainer()->get(LinkExternalItemHandler::class)('sumup', $item->id, [new RequestedLine($this->print, 'A4', 1)]);
    }

    public function testAListingLinkedToSeveralProductsSellsThemAtTheirPricesAndTheGapBecomesTheEtsyDiscount(): void
    {
        $this->authorize();
        $this->import();
        $item = self::getContainer()->get(ListExternalItemsHandler::class)('etsy')[0];

        self::getContainer()->get(LinkExternalItemHandler::class)('etsy', $item->id, [new RequestedLine($this->print, 'A4', 1), new RequestedLine($this->sticker, null, 2)]);
        $linked = array_find(self::getContainer()->get(ListExternalItemsHandler::class)('etsy'), static fn ($seen): bool => $seen->id === $item->id);
        self::assertSame([['Print Forêt', 'A4', 1], ['Sticker Mousse', null, 2]], array_map(static fn (array $component): array => [$component['name'], $component['variant'], $component['quantity']], $linked->components ?? []));

        $report = $this->import();
        self::assertSame([1, 0], [$report->ordersImported, $report->ordersWaitingForItems]);
        self::getContainer()->get('doctrine')->getManager()->clear();

        $receipt = array_values(array_filter(self::getContainer()->get(ListOrdersHandler::class)(), static fn ($order): bool => ['ETSY-3100000001'] === $order->externalReferences))[0];
        $order = self::getContainer()->get(GetOrderHandler::class)($receipt->id);
        self::assertSame([3_400, 900, 250, 2_750], [$order->subtotal, $order->discountTotal, $order->shipping, $order->total]);
        self::assertSame(-5, self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0]->onHand);
        self::assertSame([['A4', -1]], array_values(array_map(static fn ($stock): array => [$stock->variant, $stock->onHand], array_filter(self::getContainer()->get(GetProductStockHandler::class)($this->print), static fn ($stock): bool => 0 !== $stock->onHand))));
    }

    public function testACancelledReceiptIsImportedCancelledAndOneCancelledLaterIsCancelledOnTheNextImport(): void
    {
        $this->authorize();
        $receipt = static fn (string $id, ?string $cancelledAt = null): ExternalSale => ExternalSales::etsy($id, new \DateTimeImmutable('2030-01-01T10:00:00Z'), [ExternalSales::listing('1500000001', 'Sticker Mousse', 400)], cancelledAt: null === $cancelledAt ? null : new \DateTimeImmutable($cancelledAt));
        $gateway = self::getContainer()->get(FakeEtsyGateway::class);
        $cancelled = static fn (): array => array_map(
            static fn ($order): array => [$order->externalReferences[0], $order->cancelled, null !== $order->refundedAt],
            array_values(array_filter(self::getContainer()->get(ListOrdersHandler::class)(), static fn ($order): bool => 'etsy' === $order->source)),
        );
        $onHand = fn (): int => self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0]->onHand;

        $gateway->willReturn([$receipt('C1', '2030-01-02T10:00:00Z'), $receipt('C2')]);
        $first = $this->import();
        self::assertSame([2, 0], [$first->ordersImported, $first->ordersCancelled]);
        self::assertEqualsCanonicalizing([['ETSY-C1', true, true], ['ETSY-C2', false, false]], $cancelled());
        self::assertSame(-1, $onHand());

        $gateway->willReturn([$receipt('C1', '2030-01-02T10:00:00Z'), $receipt('C2', '2030-01-03T10:00:00Z')]);
        self::assertSame(1, $this->import()->ordersCancelled);
        self::assertEqualsCanonicalizing([['ETSY-C1', true, true], ['ETSY-C2', true, true]], $cancelled());
        self::assertSame(0, $onHand());
        self::assertSame(0, $this->import()->ordersCancelled);
    }

    public function testAnExpiredTokenIsRenewed(): void
    {
        $this->authorize();
        $connection = self::getContainer()->get(ServiceConnectionRepository::class)->get('etsy');
        $connection->renewToken(new \DateTimeImmutable('-1 minute'));

        self::assertSame('fake-access-renewed', self::getContainer()->get(ConnectionSession::class)->credentials($connection)->accessToken);
        self::assertGreaterThan(new \DateTimeImmutable('+30 minutes'), $connection->tokenExpiresAt());
    }

    private function authorize(): string
    {
        return self::getContainer()->get(CompleteAuthorizationHandler::class)('etsy', 'code', 'verifier', self::CALLBACK);
    }

    private function import(): \App\Application\Integration\ImportSales\ImportReport
    {
        return self::getContainer()->get(ImportSalesHandler::class)('etsy');
    }

    private function etsy(): ServiceView
    {
        $services = array_column(self::getContainer()->get(ListServicesHandler::class)(), null, 'key');

        return $services['etsy'];
    }
}
