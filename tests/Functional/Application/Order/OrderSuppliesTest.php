<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Order;

use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Order\AddOrderSupplies\AddOrderSupplies;
use App\Application\Order\AddOrderSupplies\AddOrderSuppliesHandler;
use App\Application\Order\DeleteOrders\DeleteOrders;
use App\Application\Order\DeleteOrders\DeleteOrdersHandler;
use App\Application\Order\GetOrder\GetOrderHandler;
use App\Application\Order\PlaceOrder\PlaceOrder;
use App\Application\Order\PlaceOrder\PlaceOrderHandler;
use App\Application\Order\RemoveOrderSupply\RemoveOrderSupplyHandler;
use App\Application\Order\RequestedLine;
use App\Application\Product\CreateProduct\CreateProduct;
use App\Application\Product\CreateProduct\CreateProductHandler;
use App\Application\Product\GetProduct\GetProductHandler;
use App\Application\Sales\CreateChannel\CreateChannelHandler;
use App\Application\Sales\OfferSupplies\OfferSuppliesHandler;
use App\Application\Stock\Restock\Restock;
use App\Application\Stock\Restock\RestockHandler;
use App\Domain\Event\EventRepository;
use App\Domain\Order\Exception\OrdersOnSeveralChannels;
use App\Domain\Order\Exception\SupplyNotOnChannel;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\OrderRepository;
use App\Domain\Product\ProductKind;
use App\Domain\Product\ProductRepository;
use App\Domain\Sales\ChannelKind;
use App\Domain\Sales\Exception\NotASupply;
use App\Domain\Sales\SalesChannelRepository;
use App\Domain\Stock\StockRepository;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class OrderSuppliesTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private string $sticker;
    private string $sleeve;
    private string $event;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        $container = self::getContainer();
        $this->sticker = self::createProduct('Sticker', 400);
        $container->get(RestockHandler::class)(new Restock($this->sticker, null, 10, 1_000));
        $this->sleeve = (string) $container->get(CreateProductHandler::class)(new CreateProduct('Pochette', 0, kind: ProductKind::Supply));
        $container->get(RestockHandler::class)(new Restock($this->sleeve, null, 100, 500));
        $this->event = (string) $container->get(ScheduleEventHandler::class)(new ScheduleEvent('Salon', 'Lyon', new \DateTimeImmutable('2030-03-14'), new \DateTimeImmutable('2030-03-14')));
    }

    public function testSuppliesOfferedByTheChannelAreTakenFromStockForEachOrder(): void
    {
        $this->offerOnMain([$this->sleeve]);
        [$first, $second] = [$this->placeOrder(), $this->placeOrder()];

        self::assertSame(2, $this->add([$first, $second], 2));
        $this->add([$first], 1);

        $view = self::getContainer()->get(GetOrderHandler::class)($first);
        self::assertSame([['label' => 'Pochette', 'quantity' => 3, 'cost' => 15]], array_map(static fn (array $supply): array => ['label' => $supply['label'], 'quantity' => $supply['quantity'], 'cost' => $supply['cost']], $view->supplies));
        self::assertSame([15, 400 - 100 - 15], [$view->suppliesCost, $view->margin]);
        self::assertSame(95, $this->sleevesOnHand());
        $movements = self::getContainer()->get(GetProductHandler::class)($this->sleeve)->movements;
        self::assertEqualsCanonicalizing([-3, -2], array_column(array_values(array_filter($movements, static fn (array $movement): bool => 'supply' === $movement['kind'])), 'quantity'));

        self::getContainer()->get(RemoveOrderSupplyHandler::class)($first, $view->supplies[0]['id']);
        self::getContainer()->get(DeleteOrdersHandler::class)(new DeleteOrders([$second]));
        self::assertSame(100, $this->sleevesOnHand());
    }

    public function testASupplyNotOfferedByTheOrderChannelIsRefused(): void
    {
        $order = $this->placeOrder();

        $this->expectException(SupplyNotOnChannel::class);
        $this->add([$order], 1);
    }

    public function testOnlyASupplyCanBeOffered(): void
    {
        $this->expectException(NotASupply::class);
        $this->offerOnMain([$this->sticker]);
    }

    public function testSuppliesAreAddedToOrdersOfOneChannelAtOnce(): void
    {
        $this->offerOnMain([$this->sleeve]);
        $container = self::getContainer();
        $stand = $container->get(SalesChannelRepository::class)->get($container->get(CreateChannelHandler::class)('Stand', ChannelKind::Market, null));
        $event = $container->get(EventRepository::class)->get(Ulid::fromString($this->event));
        $item = $container->get(ProductRepository::class)->get(Ulid::fromString($this->sticker))->sellableOn(null, null);
        $elsewhere = Order::place('EXT-1', $event, new \DateTimeImmutable('2030-03-14 15:00'), [new OrderedItem($item, 1)], [], $stand);
        $container->get(OrderRepository::class)->add($elsewhere);
        $container->get(EntityManagerInterface::class)->flush();

        $this->expectException(OrdersOnSeveralChannels::class);
        $this->add([$this->placeOrder(), (string) $elsewhere->id()], 1);
    }

    /**
     * @param list<string> $supplyIds
     */
    private function offerOnMain(array $supplyIds): void
    {
        self::getContainer()->get(OfferSuppliesHandler::class)((string) self::getContainer()->get(SalesChannelRepository::class)->main()->id(), $supplyIds);
    }

    public function testSeveralSuppliesAreAddedAtOnce(): void
    {
        $flyer = (string) self::getContainer()->get(CreateProductHandler::class)(new CreateProduct('Flyer', 0, kind: ProductKind::Supply));
        $this->offerOnMain([$this->sleeve, $flyer]);
        $order = $this->placeOrder();

        self::getContainer()->get(AddOrderSuppliesHandler::class)(new AddOrderSupplies([$order], [new RequestedLine($this->sleeve, null, 2), new RequestedLine($flyer, null, 1)]));

        $supplies = self::getContainer()->get(GetOrderHandler::class)($order)->supplies;
        self::assertEqualsCanonicalizing([['Pochette', 2], ['Flyer', 1]], array_map(static fn (array $supply): array => [$supply['label'], $supply['quantity']], $supplies));
    }

    private function placeOrder(): string
    {
        return (string) self::getContainer()->get(PlaceOrderHandler::class)(new PlaceOrder(new \DateTimeImmutable('2030-03-14 12:00'), [new RequestedLine($this->sticker, null, 1)]))->id();
    }

    /**
     * @param list<string> $orderIds
     */
    private function add(array $orderIds, int $quantity): int
    {
        return self::getContainer()->get(AddOrderSuppliesHandler::class)(new AddOrderSupplies($orderIds, [new RequestedLine($this->sleeve, null, $quantity)]));
    }

    private function sleevesOnHand(): int
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $container = self::getContainer();

        return $container->get(StockRepository::class)->find(Ulid::fromString($this->sleeve), null)?->onHand() ?? 0;
    }
}
