<?php

declare(strict_types=1);

namespace App\Application\Order\ListOrders;

use App\Domain\Order\ImportedSale;
use App\Domain\Order\Order;
use App\Domain\Shared\BusinessTime;

final readonly class OrderSummaryView
{
    /**
     * @param list<string> $externalReferences
     */
    public function __construct(
        public string $id,
        public string $reference,
        public string $placedAt,
        public ?string $eventId,
        public ?string $eventName,
        public int $itemCount,
        public int $subtotal,
        public int $discountTotal,
        public int $total,
        public string $source,
        public string $sourceLabel,
        public ?string $paymentMethod,
        public array $externalReferences,
        public ?string $refundedAt,
        public int $unidentifiedLines,
        public ?string $channelId,
        public ?string $channelName,
        public int $profit,
        public int $unknownCosts,
        public ?string $note,
        public bool $cancelled = false,
    ) {
    }

    public static function fromOrder(Order $order, string $sourceLabel): self
    {
        return new self(
            (string) $order->id(),
            $order->reference(),
            BusinessTime::atom($order->placedAt()),
            null === $order->event() ? null : (string) $order->event()->id(),
            $order->event()?->name(),
            $order->itemCount(),
            $order->subtotal()->amount(),
            $order->discountTotal()->amount(),
            $order->total()->amount(),
            $order->source(),
            $sourceLabel,
            $order->paymentMethod()?->value,
            array_map(static fn (ImportedSale $sale): string => $sale->reference(), $order->importedSales()),
            null === $order->refundedAt() ? null : BusinessTime::atom($order->refundedAt()),
            $order->unidentifiedLines(),
            null === $order->channel() ? null : (string) $order->channel()->id(),
            $order->channel()?->name(),
            $order->profit()->amount(),
            $order->unknownCostLines(),
            $order->note(),
            $order->isCancelled(),
        );
    }
}
