<?php

declare(strict_types=1);

namespace App\Application\Purchasing\MergeSupplierOrders;

use App\Application\Planning\OrderedRestocks;
use App\Application\Purchasing\SupplierOrderStock;
use App\Application\Transaction;
use App\Domain\Purchasing\SupplierOrderRepository;
use Symfony\Component\Uid\Ulid;

final readonly class MergeSupplierOrdersHandler
{
    public function __construct(
        private SupplierOrderRepository $orders,
        private SupplierOrderStock $stock,
        private OrderedRestocks $restocks,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $orderId, string $absorbedOrderId): void
    {
        $order = $this->orders->get(Ulid::fromString($orderId));
        $absorbed = $this->orders->get(Ulid::fromString($absorbedOrderId));
        $order->absorb($absorbed);
        $this->stock->moveInto($absorbed, $order);
        $this->restocks->moveInto($absorbed, $order);
        $this->orders->remove($absorbed);
        $this->transaction->commit();
    }
}
