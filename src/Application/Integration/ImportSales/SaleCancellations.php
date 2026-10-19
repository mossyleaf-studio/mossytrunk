<?php

declare(strict_types=1);

namespace App\Application\Integration\ImportSales;

use App\Application\Integration\ExternalSale;
use App\Application\Stock\StockKeeper;
use App\Domain\Order\Order;
use App\Domain\Order\OrderRepository;

final readonly class SaleCancellations
{
    public function __construct(
        private OrderRepository $orders,
        private StockKeeper $stock,
    ) {
    }

    /**
     * @param array<array-key, ExternalSale> $sales
     */
    public function cancelImported(string $service, array $sales): int
    {
        $cancelled = [];
        foreach ($sales as $sale) {
            if (null !== $sale->cancelledAt) {
                $cancelled[$sale->id] = $sale->cancelledAt;
            }
        }

        $count = 0;
        foreach ($this->orders->importedAsOneOf($service, array_map(strval(...), array_keys($cancelled))) as $order) {
            $cancelledAt = self::cancelledAt($order, $cancelled);
            if (null !== $cancelledAt && !$order->isRefunded()) {
                $this->cancel($order, $cancelledAt);
                ++$count;
            }
        }

        return $count;
    }

    public function cancel(Order $order, \DateTimeImmutable $cancelledAt): void
    {
        $order->cancel($cancelledAt);
        $this->stock->takeBack($order, $cancelledAt);
    }

    /**
     * @param array<array-key, \DateTimeImmutable> $cancelled
     */
    private static function cancelledAt(Order $order, array $cancelled): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($order->importedSales() as $sale) {
            $cancelledAt = $cancelled[$sale->externalId()] ?? null;
            if (null === $cancelledAt) {
                return null;
            }
            $latest = max($latest, $cancelledAt);
        }

        return $latest;
    }
}
