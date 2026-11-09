<?php

declare(strict_types=1);

namespace App\Application\Purchasing\ReviseSupplierOrder;

use App\Application\Planning\OrderedRestocks;
use App\Application\Purchasing\PurchasedItems;
use App\Application\Purchasing\SupplierOrderDraft;
use App\Application\Purchasing\SupplierOrderStock;
use App\Application\Transaction;
use App\Domain\Purchasing\SupplierOrderRepository;
use App\Domain\Purchasing\SupplierRepository;
use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final readonly class ReviseSupplierOrderHandler
{
    public function __construct(
        private SupplierRepository $suppliers,
        private SupplierOrderRepository $orders,
        private PurchasedItems $items,
        private SupplierOrderStock $stock,
        private OrderedRestocks $restocks,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $orderId, SupplierOrderDraft $draft): void
    {
        $order = $this->orders->get(Ulid::fromString($orderId));
        $order->revise(
            $this->suppliers->get(Ulid::fromString($draft->supplierId)),
            $draft->orderedOn,
            $this->items->of($draft->lines),
            Money::cents($draft->discountCents),
            Money::cents($draft->deliveryFeesCents),
        );
        $order->referToSupplierOrder($draft->supplierReference);
        $order->priceIn($draft->currency, $draft->exchangeRateMicros);
        if (null !== $draft->receivedOn) {
            $order->redateReception($draft->receivedOn);
        }
        $this->stock->follow($order);
        $this->restocks->follow($order);
        $this->transaction->commit();
    }
}
