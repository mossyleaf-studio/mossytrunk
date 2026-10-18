<?php

declare(strict_types=1);

namespace App\Application\Order\GetOrder;

use App\Domain\Discount\AppliedDiscount;
use App\Domain\Order\ImportedSale;
use App\Domain\Order\Order;
use App\Domain\Order\OrderLine;
use App\Domain\Order\OrderSupply;
use App\Domain\Sales\OrderCharge;
use App\Domain\Shared\BusinessTime;

final readonly class OrderView
{
    /**
     * @param list<array{id: string, productId: ?string, label: string, quantity: int, unitPrice: int, total: int, unitCost: int, cost: int}> $lines
     * @param array{id: string, name: string}|null                                                                                            $event
     * @param list<array{reference: string}>                                                                                                  $importedSales
     * @param list<array{label: string, amount: int, ruleId: ?string, manual?: array{kind: string, value: int}}>                              $discounts
     * @param list<array{id: string, productId: string, label: string, quantity: int, cost: int}>                                             $supplies
     * @param list<array{label: string, amount: int}>                                                                                         $charges
     */
    public function __construct(
        public string $id,
        public string $reference,
        public string $placedAt,
        public ?array $event,
        public string $source,
        public string $sourceLabel,
        public ?string $paymentMethod,
        public array $lines,
        public array $discounts,
        public int $subtotal,
        public int $discountTotal,
        public int $shipping,
        public int $total,
        public int $costOfGoods,
        public int $margin,
        public array $importedSales,
        public ?string $refundedAt,
        public ?string $channelName,
        public ?string $channelId,
        public array $supplies,
        public int $suppliesCost,
        public array $charges,
        public int $postage,
        public int $channelCosts,
        public ?string $note,
    ) {
    }

    public static function fromOrder(Order $order, string $sourceLabel): self
    {
        return new self(
            (string) $order->id(),
            $order->reference(),
            BusinessTime::atom($order->placedAt()),
            null === $order->event() ? null : ['id' => (string) $order->event()->id(), 'name' => $order->event()->name()],
            $order->source(),
            $sourceLabel,
            $order->paymentMethod()?->value,
            array_map(static fn (OrderLine $line): array => [
                'id' => (string) $line->id(),
                'productId' => null === $line->productId() ? null : (string) $line->productId(),
                'label' => $line->label(),
                'quantity' => $line->quantity(),
                'unitPrice' => $line->unitPrice()->amount(),
                'total' => $line->total()->amount(),
                'unitCost' => $line->unitCost()->amount(),
                'cost' => $line->cost()->amount(),
            ], $order->lines()),
            array_map(static fn (AppliedDiscount $discount): array => $discount->toArray(), $order->appliedDiscounts()),
            $order->subtotal()->amount(),
            $order->discountTotal()->amount(),
            $order->shipping()->amount(),
            $order->total()->amount(),
            $order->costOfGoods()->amount(),
            $order->profit()->amount(),
            array_map(static fn (ImportedSale $sale): array => ['reference' => $sale->reference()], $order->importedSales()),
            null === $order->refundedAt() ? null : BusinessTime::atom($order->refundedAt()),
            $order->channel()?->name(),
            null === $order->channel() ? null : (string) $order->channel()->id(),
            array_map(static fn (OrderSupply $supply): array => [
                'id' => (string) $supply->id(),
                'productId' => (string) $supply->productId(),
                'label' => $supply->label(),
                'quantity' => $supply->quantity(),
                'cost' => $supply->cost()->amount(),
            ], $order->supplies()),
            $order->suppliesCost()->amount(),
            array_map(static fn (OrderCharge $charge): array => $charge->toArray(), $order->channelCharges()),
            $order->postage()->amount(),
            $order->channelCosts()->amount(),
            $order->note(),
        );
    }
}
