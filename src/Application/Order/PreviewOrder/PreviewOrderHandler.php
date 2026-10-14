<?php

declare(strict_types=1);

namespace App\Application\Order\PreviewOrder;

use App\Application\Order\OrderPricing;
use App\Application\Order\RequestedLine;
use App\Domain\Discount\AppliedDiscount;
use App\Domain\Event\EventRepository;
use App\Domain\Order\OrderedItem;
use App\Domain\Shared\Money;

final readonly class PreviewOrderHandler
{
    public function __construct(
        private EventRepository $events,
        private OrderPricing $pricing,
    ) {
    }

    /**
     * @param list<RequestedLine> $lines
     * @param list<string>        $discountRuleIds
     */
    public function __invoke(\DateTimeImmutable $placedAt, array $lines, array $discountRuleIds = []): OrderPreview
    {
        $event = $this->events->findCovering($placedAt);
        $items = $this->pricing->items($lines, $placedAt);
        $discounts = $this->pricing->discounts($items, $placedAt, null, $discountRuleIds);

        $subtotal = Money::sum(array_map(static fn (OrderedItem $ordered): Money => $ordered->item->sellingPrice->multiply($ordered->quantity), $items));
        $discountTotal = AppliedDiscount::total($discounts);

        return new OrderPreview(
            null === $event ? null : ['id' => (string) $event->id(), 'name' => $event->name()],
            $subtotal->amount(),
            array_map(static fn (AppliedDiscount $discount): array => $discount->toArray(), $discounts),
            $discountTotal->amount(),
            $subtotal->subtract($discountTotal)->amount(),
        );
    }
}
