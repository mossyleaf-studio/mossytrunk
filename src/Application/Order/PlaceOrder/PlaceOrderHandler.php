<?php

declare(strict_types=1);

namespace App\Application\Order\PlaceOrder;

use App\Application\Order\OrderPricing;
use App\Application\Reference\ReferenceGenerator;
use App\Application\Sales\OrderChannel;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Domain\Event\EventRepository;
use App\Domain\Order\Exception\NoEventOnOrderDate;
use App\Domain\Order\Order;
use App\Domain\Order\OrderRepository;
use App\Domain\Reference\ReferenceKind;
use App\Domain\Reference\ReferenceSubject;

final readonly class PlaceOrderHandler
{
    public function __construct(
        private EventRepository $events,
        private OrderRepository $orders,
        private OrderPricing $pricing,
        private StockKeeper $stock,
        private ReferenceGenerator $references,
        private Transaction $transaction,
        private OrderChannel $orderChannel,
    ) {
    }

    public function __invoke(PlaceOrder $command): Order
    {
        $event = $this->events->findCovering($command->placedAt) ?? throw new NoEventOnOrderDate($command->placedAt);

        $items = $this->stock->withdraw($event, $this->pricing->items($command->lines, $command->placedAt));
        $order = Order::place($this->references->next(ReferenceKind::Order, ReferenceSubject::at($command->placedAt)), $event, $command->placedAt, $items, $this->pricing->discounts($items, $command->placedAt, null, $command->discountRuleIds), $this->orderChannel->of(null, $event));

        $this->orders->add($order);
        $this->transaction->commit();

        return $order;
    }
}
