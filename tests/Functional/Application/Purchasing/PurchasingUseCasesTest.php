<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Purchasing;

use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Order\AddOrderSupplies\AddOrderSupplies;
use App\Application\Order\AddOrderSupplies\AddOrderSuppliesHandler;
use App\Application\Order\FillMissingCosts\FillMissingCostsHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\ListOrders\ListOrdersHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\CreateProduct\CreateProduct;
use App\Application\Product\CreateProduct\CreateProductHandler;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Product\GetProduct\GetProductHandler;
use App\Application\Product\ListProducts\ProductView;
use App\Application\Purchasing\CreateSupplier\CreateSupplier;
use App\Application\Purchasing\CreateSupplier\CreateSupplierHandler;
use App\Application\Purchasing\DeleteSupplierOrder\DeleteSupplierOrderHandler;
use App\Application\Purchasing\GetSupplierOrder\GetSupplierOrderHandler;
use App\Application\Purchasing\ListSupplierOrders\ListSupplierOrdersHandler;
use App\Application\Purchasing\ListSuppliers\ListSuppliersHandler;
use App\Application\Purchasing\MergeSupplierOrders\MergeSupplierOrdersHandler;
use App\Application\Purchasing\PlaceSupplierOrder\PlaceSupplierOrderHandler;
use App\Application\Purchasing\PurchaseLine;
use App\Application\Purchasing\ReceiveSupplierOrder\ReceiveSupplierOrderHandler;
use App\Application\Purchasing\ReviseSupplierOrder\ReviseSupplierOrderHandler;
use App\Application\Purchasing\SupplierOrderDraft;
use App\Application\Purchasing\SupplierOrderView;
use App\Application\Sales\OfferSupplies\OfferSuppliesHandler;
use App\Application\Stock\GetProductStock\GetProductStockHandler;
use App\Domain\Product\ProductKind;
use App\Domain\Purchasing\Currency;
use App\Domain\Purchasing\Exception\InvalidPurchase;
use App\Domain\Sales\SalesChannelRepository;
use App\Domain\Shared\Exception\NotFound;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PurchasingUseCasesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;
    private string $supplierId;
    private string $sticker;
    private string $tshirt;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        $this->supplierId = (string) self::getContainer()->get(CreateSupplierHandler::class)(new CreateSupplier('Imprimerie du Lac'))->id();
        $this->sticker = (string) self::createProduct('Sticker', 400, 30);
        $this->tshirt = (string) self::createProduct('T-shirt', 2_000, 900, ['S', 'M']);
    }

    public function testReceivingAnOrderStocksWhatArrivedAtTheRealUnitCost(): void
    {
        $orderId = $this->place([new PurchaseLine($this->sticker, null, 100, 2_000), new PurchaseLine($this->tshirt, 'M', 10, 8_000)]);
        $lines = $this->view($orderId)->lines;

        self::getContainer()->get(ReceiveSupplierOrderHandler::class)($orderId, [$lines[0]['id'] => 125, $lines[1]['id'] => 8]);
        $this->clear();

        $view = $this->view($orderId);
        self::assertSame('received', $view->status);
        self::assertSame(16, $view->lines[0]['unitCost']);
        self::assertSame(1_000, $view->lines[1]['unitCost']);

        $stickerStock = self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0];
        self::assertSame(125, $stickerStock->onHand);
        self::assertSame('supplier_order', $stickerStock->lots[0]['origin']);
        self::assertSame($orderId, $stickerStock->lots[0]['sourceId']);
        $medium = self::getContainer()->get(GetProductStockHandler::class)($this->tshirt)[1];
        self::assertSame(8, $medium->onHand);
        self::assertSame(8_000, $medium->remainingValue);
    }

    public function testDiscountAndDeliveryFeesAreStockedWithTheLines(): void
    {
        $orderId = (string) self::getContainer()->get(PlaceSupplierOrderHandler::class)(new SupplierOrderDraft(
            $this->supplierId,
            new \DateTimeImmutable('2026-09-01'),
            [new PurchaseLine($this->sticker, null, 100, 2_000), new PurchaseLine($this->tshirt, 'S', 10, 2_000)],
            400,
            600,
        ));
        $lines = $this->view($orderId)->lines;
        self::assertSame(4_200, $this->view($orderId)->total);

        self::getContainer()->get(ReceiveSupplierOrderHandler::class)($orderId, [$lines[0]['id'] => 100, $lines[1]['id'] => 10]);
        $this->clear();

        $sticker = self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0];
        self::assertSame(2_000 - 200 + 300, $sticker->remainingValue);
    }

    public function testAReceivedOrderIsCorrectedAndItsStockFollows(): void
    {
        $orderId = $this->place([new PurchaseLine($this->sticker, null, 100, 2_000), new PurchaseLine($this->tshirt, 'M', 10, 8_000)]);
        $lines = $this->view($orderId)->lines;
        self::getContainer()->get(ReceiveSupplierOrderHandler::class)($orderId, [$lines[0]['id'] => 100, $lines[1]['id'] => 10]);
        $this->clear();

        self::getContainer()->get(ReviseSupplierOrderHandler::class)($orderId, new SupplierOrderDraft($this->supplierId, new \DateTimeImmutable('2026-09-01'), [
            new PurchaseLine($this->sticker, null, 100, 2_400, 80),
            new PurchaseLine($this->tshirt, 'S', 5, 4_000, 5),
        ], supplierReference: 'FAC-42', receivedOn: new \DateTimeImmutable('2026-09-05 12:00', new \DateTimeZone('Europe/Paris'))));
        $this->clear();

        $view = $this->view($orderId);
        self::assertSame(['received', 30, '2026-09-05', 'FAC-42'], [$view->status, $view->lines[0]['unitCost'], $view->receivedOn, $view->supplierReference]);
        $sticker = self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0];
        self::assertSame([80, 2_400], [$sticker->onHand, $sticker->remainingValue]);
        [$small, $medium] = self::getContainer()->get(GetProductStockHandler::class)($this->tshirt);
        self::assertSame([5, 0], [$small->onHand, $medium->onHand]);
        self::assertSame(30, $this->productView($this->sticker)->buyingPrice);
    }

    public function testSalesMadeBeforeAnyCostWasKnownTakeTheCostOfTheFirstPurchase(): void
    {
        $container = self::getContainer();
        $badge = self::createProduct('Badge', 500, typeId: (string) $container->get(CreateProductTypeHandler::class)('Badge')->id());
        $container->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-14')));
        $sold = (string) $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 12:00'), [new RequestedLine($badge, null, 3)]))->id();
        self::assertSame([0, 1], [$container->get(GetOrderHandler::class)($sold)->costOfGoods, $container->get(ListOrdersHandler::class)()[0]->unknownCosts]);

        $orderId = $this->place([new PurchaseLine($badge, null, 10, 1_200)]);
        $container->get(ReceiveSupplierOrderHandler::class)($orderId, [$this->view($orderId)->lines[0]['id'] => 10]);
        $this->clear();

        $order = $container->get(GetOrderHandler::class)($sold);
        self::assertSame([360, 1_500 - 360], [$order->costOfGoods, $order->margin]);
        self::assertSame([1_500 - 360, 0], [$container->get(ListOrdersHandler::class)()[0]->profit, $container->get(ListOrdersHandler::class)()[0]->unknownCosts]);
        self::assertSame(0, $container->get(FillMissingCostsHandler::class)());
    }

    public function testCorrectingAReceivedOrderRecostsTheOrdersThatTookItsUnits(): void
    {
        $container = self::getContainer();
        $sleeve = (string) $container->get(CreateProductHandler::class)(new CreateProduct('Pochette', 0, typeId: (string) $container->get(CreateProductTypeHandler::class)('Pochette')->id(), kind: ProductKind::Supply));
        $container->get(OfferSuppliesHandler::class)((string) $container->get(SalesChannelRepository::class)->main()->id(), [$sleeve]);
        $orderId = $this->place([new PurchaseLine($sleeve, null, 1_000, 2_000), new PurchaseLine($this->sticker, null, 100, 2_000)]);
        $lines = $this->view($orderId)->lines;
        $container->get(ReceiveSupplierOrderHandler::class)($orderId, [$lines[0]['id'] => 1_000, $lines[1]['id'] => 100]);
        $container->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-14')));
        $sold = (string) $container->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 12:00'), [new RequestedLine($this->sticker, null, 2)]))->id();
        $container->get(AddOrderSuppliesHandler::class)(new AddOrderSupplies([$sold], [new RequestedLine($sleeve, null, 3)]));
        self::assertSame([40, 6], [$container->get(GetOrderHandler::class)($sold)->costOfGoods, $container->get(GetOrderHandler::class)($sold)->suppliesCost]);

        $container->get(ReviseSupplierOrderHandler::class)($orderId, new SupplierOrderDraft($this->supplierId, new \DateTimeImmutable('2026-09-01'), [
            new PurchaseLine($sleeve, null, 500, 2_000, 500),
            new PurchaseLine($this->sticker, null, 100, 3_000, 100),
        ]));
        $this->clear();

        $order = $container->get(GetOrderHandler::class)($sold);
        self::assertSame([60, 12], [$order->costOfGoods, $order->suppliesCost]);
    }

    public function testAnOrderInDollarsStocksItsCostInEuros(): void
    {
        $orderId = (string) self::getContainer()->get(PlaceSupplierOrderHandler::class)(new SupplierOrderDraft(
            $this->supplierId,
            new \DateTimeImmutable('2026-09-01'),
            [new PurchaseLine($this->sticker, null, 100, 2_000)],
            deliveryFeesCents: 1_000,
            currency: Currency::Dollar,
            exchangeRateMicros: 900_000,
        ));
        self::getContainer()->get(ReceiveSupplierOrderHandler::class)($orderId, [$this->view($orderId)->lines[0]['id'] => 100]);
        $this->clear();

        $view = $this->view($orderId);
        self::assertSame(['USD', 0.9, 3_000, 2_700, 27], [$view->currency, $view->exchangeRate, $view->total, $view->totalInEuros, $view->lines[0]['unitCost']]);
        self::assertSame(2_700, self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0]->remainingValue);
    }

    public function testAReceivedOrderNeedsEveryReceivedQuantity(): void
    {
        $orderId = $this->place([new PurchaseLine($this->sticker, null, 10, 300)]);
        self::getContainer()->get(ReceiveSupplierOrderHandler::class)($orderId, [$this->view($orderId)->lines[0]['id'] => 10]);

        $this->expectException(InvalidPurchase::class);
        self::getContainer()->get(ReviseSupplierOrderHandler::class)($orderId, new SupplierOrderDraft($this->supplierId, new \DateTimeImmutable('2026-09-01'), [new PurchaseLine($this->sticker, null, 10, 300)]));
    }

    public function testTwoOrdersOfTheSameSupplierAreMergedLineByLine(): void
    {
        $first = $this->place([new PurchaseLine($this->sticker, null, 100, 2_000)]);
        $second = (string) self::getContainer()->get(PlaceSupplierOrderHandler::class)(new SupplierOrderDraft($this->supplierId, new \DateTimeImmutable('2026-08-20'), [new PurchaseLine($this->sticker, null, 50, 1_000), new PurchaseLine($this->tshirt, 'S', 2, 1_600)], 0, 500));

        self::getContainer()->get(MergeSupplierOrdersHandler::class)($first, $second);
        $this->clear();

        $view = $this->view($first);
        self::assertSame(['2026-08-20', 500], [$view->orderedOn, $view->deliveryFees]);
        self::assertSame([['Sticker', 150, 3_000], ['T-shirt — S', 2, 1_600]], array_map(static fn (array $line): array => [$line['label'], $line['orderedQuantity'], $line['totalPrice']], $view->lines));
        self::assertCount(1, self::getContainer()->get(ListSupplierOrdersHandler::class)());
    }

    public function testMergingReceivedOrdersKeepsTheirStockInOneLot(): void
    {
        $first = $this->place([new PurchaseLine($this->sticker, null, 100, 2_000)]);
        $second = $this->place([new PurchaseLine($this->sticker, null, 100, 4_000)]);
        foreach ([$first, $second] as $orderId) {
            self::getContainer()->get(ReceiveSupplierOrderHandler::class)($orderId, [$this->view($orderId)->lines[0]['id'] => 100]);
        }
        $this->clear();

        self::getContainer()->get(MergeSupplierOrdersHandler::class)($first, $second);
        $this->clear();

        $stock = self::getContainer()->get(GetProductStockHandler::class)($this->sticker)[0];
        self::assertSame([200, 6_000, 1], [$stock->onHand, $stock->remainingValue, \count($stock->lots)]);
        self::assertSame(30, $this->view($first)->lines[0]['unitCost']);
    }

    public function testOnlyOrdersOfTheSameSupplierAndStatusAreMerged(): void
    {
        $first = $this->place([new PurchaseLine($this->sticker, null, 10, 300)]);
        $second = $this->place([new PurchaseLine($this->sticker, null, 10, 300)]);
        self::getContainer()->get(ReceiveSupplierOrderHandler::class)($second, [$this->view($second)->lines[0]['id'] => 10]);

        $this->expectException(InvalidPurchase::class);
        self::getContainer()->get(MergeSupplierOrdersHandler::class)($first, $second);
    }

    public function testAReceivedOrderCannotBeDeleted(): void
    {
        $orderId = $this->place([new PurchaseLine($this->sticker, null, 10, 300)]);
        self::getContainer()->get(ReceiveSupplierOrderHandler::class)($orderId, [$this->view($orderId)->lines[0]['id'] => 10]);

        $this->expectException(InvalidPurchase::class);

        self::getContainer()->get(DeleteSupplierOrderHandler::class)($orderId);
    }

    public function testAnOrderedOrderCanBeRevisedThenDeleted(): void
    {
        $orderId = $this->place([new PurchaseLine($this->sticker, null, 10, 300)]);

        self::getContainer()->get(ReviseSupplierOrderHandler::class)($orderId, new SupplierOrderDraft($this->supplierId, new \DateTimeImmutable('2026-09-02'), [new PurchaseLine($this->tshirt, 'S', 4, 3_600)]));
        $this->clear();

        $view = $this->view($orderId);
        self::assertSame('2026-09-02', $view->orderedOn);
        self::assertSame(['T-shirt — S'], array_column($view->lines, 'label'));

        self::getContainer()->get(DeleteSupplierOrderHandler::class)($orderId);
        self::assertSame([], self::getContainer()->get(ListSupplierOrdersHandler::class)());
    }

    public function testSupplierNamesAreUniquePerWorkspace(): void
    {
        $this->expectException(InvalidPurchase::class);

        self::getContainer()->get(CreateSupplierHandler::class)(new CreateSupplier('imprimerie du lac'));
    }

    public function testSuppliersAndOrdersBelongToTheWorkspace(): void
    {
        $orderId = $this->place([new PurchaseLine($this->sticker, null, 10, 300)]);

        self::actAsMemberOf('Autre atelier');

        self::assertSame([], self::getContainer()->get(ListSuppliersHandler::class)());
        $this->expectException(NotFound::class);
        $this->view($orderId);
    }

    /**
     * @param list<PurchaseLine> $lines
     */
    private function place(array $lines): string
    {
        return (string) self::getContainer()->get(PlaceSupplierOrderHandler::class)(new SupplierOrderDraft($this->supplierId, new \DateTimeImmutable('2026-09-01'), $lines));
    }

    private function productView(string $productId): ProductView
    {
        return self::getContainer()->get(GetProductHandler::class)($productId)->product;
    }

    private function view(string $orderId): SupplierOrderView
    {
        return self::getContainer()->get(GetSupplierOrderHandler::class)($orderId);
    }

    private function clear(): void
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
    }
}
