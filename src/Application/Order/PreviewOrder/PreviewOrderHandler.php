<?php

declare(strict_types=1);

namespace App\Application\Order\PreviewOrder;

use App\Application\Order\OrderPricing;
use App\Application\Order\RequestedLine;
use App\Domain\Discount\AppliedDiscount;
use App\Domain\Order\OrderedItem;
use App\Domain\Sales\SalesChannelRepository;
use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final readonly class PreviewOrderHandler
{
    public function __construct(
        private SalesChannelRepository $channels,
        private OrderPricing $pricing,
    ) {
    }

    /**
     * @param list<RequestedLine> $lines
     * @param list<string>        $discountRuleIds
     */
    public function __invoke(\DateTimeImmutable $placedAt, array $lines, array $discountRuleIds = [], ?string $channelId = null): OrderPreview
    {
        $channel = null === $channelId ? null : $this->channels->get(Ulid::fromString($channelId));
        $items = $this->pricing->items($lines, $placedAt, $channel);
        $discounts = $this->pricing->discounts($items, $placedAt, null, $discountRuleIds);

        $subtotal = Money::sum(array_map(static fn (OrderedItem $ordered): Money => $ordered->item->sellingPrice->multiply($ordered->quantity), $items));
        $discountTotal = AppliedDiscount::total($discounts);

        return new OrderPreview(
            $subtotal->amount(),
            array_map(static fn (AppliedDiscount $discount): array => $discount->toArray(), $discounts),
            $discountTotal->amount(),
            $subtotal->subtract($discountTotal)->amount(),
        );
    }
}
