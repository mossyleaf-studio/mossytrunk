<?php

declare(strict_types=1);

namespace App\Application\Order\AddOrderSupplies;

use App\Application\Order\RequestedLine;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Domain\Order\Exception\OrdersOnSeveralChannels;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\OrderRepository;
use App\Domain\Product\ProductRepository;
use Symfony\Component\Uid\Ulid;

final readonly class AddOrderSuppliesHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ProductRepository $products,
        private StockKeeper $stock,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(AddOrderSupplies $command): int
    {
        $orders = $this->orders->getMany(array_map(Ulid::fromString(...), array_values(array_unique($command->orderIds))));
        $this->assertOneChannel($orders);
        $supplies = array_map(fn (RequestedLine $line): OrderedItem => new OrderedItem($this->products->get(Ulid::fromString($line->productId))->sellable($line->variant), $line->quantity), $command->supplies);

        foreach ($orders as $order) {
            foreach ($this->stock->withdraw($order->event(), $supplies) as $used) {
                $order->useSupply($used->item, $used->quantity, $used->cost());
            }
        }
        $this->transaction->commit();

        return \count($orders);
    }

    /**
     * @param list<Order> $orders
     */
    private function assertOneChannel(array $orders): void
    {
        $channels = array_unique(array_map(static fn (Order $order): string => (string) $order->channel()?->id(), $orders));
        if (\count($channels) > 1) {
            throw new OrdersOnSeveralChannels();
        }
    }
}
