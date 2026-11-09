<?php

declare(strict_types=1);

namespace App\Application\Product;

use App\Application\Integration\Connectors;
use App\Domain\Order\Order;
use App\Domain\Order\OrderRepository;
use App\Domain\Product\Product;
use App\Domain\Stock\LotOrigin;
use App\Domain\Stock\StockCheckRepository;
use App\Domain\Stock\StockItem;
use App\Domain\Stock\StockLot;
use Symfony\Component\Uid\Ulid;

final readonly class ProductMovements
{
    public function __construct(
        private OrderRepository $orders,
        private StockCheckRepository $checks,
        private Connectors $connectors,
    ) {
    }

    /**
     * @param list<StockItem> $stockItems
     *
     * @return list<array{date: string, kind: string, variant: ?string, quantity: int, cost: int, link: ?string, label: ?string}>
     */
    public function of(Product $product, array $stockItems): array
    {
        $movements = [
            ...$this->lotMovements($stockItems),
            ...$this->saleMovements($product->id()),
            ...$product->isSupply() ? $this->supplyMovements($product->id()) : [],
            ...$this->lossMovements($product->id()),
        ];
        usort($movements, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $movements;
    }

    /**
     * @param list<StockItem> $stockItems
     *
     * @return list<array{date: string, kind: string, variant: ?string, quantity: int, cost: int, link: ?string, label: ?string}>
     */
    private function lotMovements(array $stockItems): array
    {
        $movements = [];
        foreach ($stockItems as $item) {
            foreach ($item->lots() as $lot) {
                $movements[] = [
                    'date' => $lot->receivedAt()->format(\DateTimeInterface::ATOM),
                    'kind' => $lot->origin()->value,
                    'variant' => $item->variant(),
                    'quantity' => $lot->quantity(),
                    'cost' => $lot->totalCost()->amount(),
                    'link' => $this->lotLink($lot),
                    'label' => null,
                ];
            }
        }

        return $movements;
    }

    /**
     * @return list<array{date: string, kind: string, variant: ?string, quantity: int, cost: int, link: ?string, label: ?string}>
     */
    private function saleMovements(Ulid $productId): array
    {
        $movements = [];
        foreach ($this->orders->selling($productId) as $order) {
            foreach ($order->lines() as $line) {
                if ($line->productId()?->equals($productId) ?? false) {
                    $movements[] = [
                        'date' => $order->placedAt()->format(\DateTimeInterface::ATOM),
                        'kind' => 'sale',
                        'variant' => $line->variant(),
                        'quantity' => -$line->quantity(),
                        'cost' => $line->cost()->amount(),
                        'link' => '/orders/'.$order->id(),
                        'label' => $this->placeOf($order),
                    ];
                }
            }
        }

        return $movements;
    }

    /**
     * @return list<array{date: string, kind: string, variant: ?string, quantity: int, cost: int, link: ?string, label: ?string}>
     */
    private function supplyMovements(Ulid $productId): array
    {
        $movements = [];
        foreach ($this->orders->using($productId) as $order) {
            foreach ($order->supplies() as $supply) {
                if ($supply->productId()->equals($productId)) {
                    $movements[] = [
                        'date' => $order->placedAt()->format(\DateTimeInterface::ATOM),
                        'kind' => 'supply',
                        'variant' => $supply->variant(),
                        'quantity' => -$supply->quantity(),
                        'cost' => $supply->cost()->amount(),
                        'link' => '/orders/'.$order->id(),
                        'label' => $order->reference(),
                    ];
                }
            }
        }

        return $movements;
    }

    /**
     * @return list<array{date: string, kind: string, variant: ?string, quantity: int, cost: int, link: ?string, label: ?string}>
     */
    private function lossMovements(Ulid $productId): array
    {
        $movements = [];
        foreach ($this->checks->counting($productId) as $check) {
            foreach ($check->lines() as $line) {
                if ($line->productId()->equals($productId) && $line->missing() > 0) {
                    $movements[] = [
                        'date' => $check->checkedAt()->format(\DateTimeInterface::ATOM),
                        'kind' => 'loss',
                        'variant' => $line->variant(),
                        'quantity' => -$line->missing(),
                        'cost' => $line->lossCost()->amount(),
                        'link' => '/events/'.$check->event()->id(),
                        'label' => $check->event()->name(),
                    ];
                }
            }
        }

        return $movements;
    }

    private function placeOf(Order $order): string
    {
        return $order->event()?->name() ?? $this->connectors->labelOf($order->source());
    }

    private function lotLink(StockLot $lot): ?string
    {
        return match (true) {
            null === $lot->sourceId() => null,
            LotOrigin::Return === $lot->origin() => '/orders/'.$lot->sourceId(),
            default => '/supplier-orders/'.$lot->sourceId(),
        };
    }
}
