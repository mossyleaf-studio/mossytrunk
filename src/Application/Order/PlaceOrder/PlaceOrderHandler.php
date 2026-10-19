<?php

declare(strict_types=1);

namespace App\Application\Order\PlaceOrder;

use App\Application\Order\OrderPricing;
use App\Application\Reference\ReferenceGenerator;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Domain\Event\EventRepository;
use App\Domain\Order\Exception\NoEventOnOrderDate;
use App\Domain\Order\Order;
use App\Domain\Order\OrderRepository;
use App\Domain\Reference\ReferenceKind;
use App\Domain\Reference\ReferenceSubject;
use App\Domain\Sales\SalesChannelRepository;
use Symfony\Component\Uid\Ulid;

final readonly class PlaceOrderHandler
{
    public function __construct(
        private EventRepository $events,
        private OrderRepository $orders,
        private OrderPricing $pricing,
        private StockKeeper $stock,
        private ReferenceGenerator $references,
        private Transaction $transaction,
        private SalesChannelRepository $channels,
    ) {
    }

    public function __invoke(PlaceOrder $command): Order
    {
        $channel = null === $command->channelId ? $this->channels->main() : $this->channels->get(Ulid::fromString($command->channelId));
        $event = match (true) {
            null !== $command->eventId => $this->events->get(Ulid::fromString($command->eventId)),
            $channel->acceptsOrderWithoutEvent() => null,
            default => $this->events->findCovering($command->placedAt) ?? throw new NoEventOnOrderDate($command->placedAt),
        };

        $items = $this->stock->withdraw($event, $this->pricing->items($command->lines, $command->placedAt, $channel));
        $reference = $this->references->next(ReferenceKind::Order, ReferenceSubject::at($command->placedAt));
        $discounts = $this->pricing->discounts($items, $command->placedAt, null, $command->discountRuleIds);
        $order = null === $event
            ? Order::placeOn($reference, $channel, $command->placedAt, $items, $discounts)
            : Order::place($reference, $event, $command->placedAt, $items, $discounts, $channel);
        $order->annotate($command->note);

        $this->orders->add($order);
        $this->transaction->commit();

        return $order;
    }
}
