<?php

declare(strict_types=1);

namespace App\Application\Order\PlaceOrder;

use App\Application\Order\OrderPricing;
use App\Application\Reference\ReferenceGenerator;
use App\Application\Sales\OrderChannel;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Application\WorkspaceContext;
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
        private WorkspaceContext $workspace,
    ) {
    }

    public function __invoke(PlaceOrder $command): Order
    {
        $event = $command->outsideEvents ? null : ($this->events->findCovering($command->placedAt) ?? throw new NoEventOnOrderDate($command->placedAt));

        $items = $this->stock->withdraw($event, $this->pricing->items($command->lines, $command->placedAt));
        $reference = $this->references->next(ReferenceKind::Order, ReferenceSubject::at($command->placedAt));
        $discounts = $this->pricing->discounts($items, $command->placedAt, null, $command->discountRuleIds);
        $order = null === $event
            ? Order::placeOutsideEvents($reference, $this->workspace->current(), $command->placedAt, $items, $discounts)
            : Order::place($reference, $event, $command->placedAt, $items, $discounts, $this->orderChannel->of(null, $event));
        $order->annotate($command->note);

        $this->orders->add($order);
        $this->transaction->commit();

        return $order;
    }
}
