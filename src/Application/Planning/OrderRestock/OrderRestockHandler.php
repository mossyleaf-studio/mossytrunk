<?php

declare(strict_types=1);

namespace App\Application\Planning\OrderRestock;

use App\Application\Transaction;
use App\Domain\Planning\RestockRepository;
use App\Domain\Purchasing\SupplierOrderRepository;
use Symfony\Component\Uid\Ulid;

final readonly class OrderRestockHandler
{
    public function __construct(
        private RestockRepository $restocks,
        private SupplierOrderRepository $supplierOrders,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $restockId, string $supplierOrderId): void
    {
        $this->restocks->get(Ulid::fromString($restockId))->orderedThrough($this->supplierOrders->get(Ulid::fromString($supplierOrderId)));
        $this->transaction->commit();
    }
}
