<?php

declare(strict_types=1);

namespace App\Application\Order\WithdrawOrderDiscount;

use App\Application\Order\OrderCharges;
use App\Application\Transaction;
use App\Domain\Order\OrderRepository;
use Symfony\Component\Uid\Ulid;

final readonly class WithdrawOrderDiscountHandler
{
    public function __construct(
        private OrderRepository $orders,
        private OrderCharges $charges,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $orderId, string $ruleId): void
    {
        $order = $this->orders->get(Ulid::fromString($orderId));
        $order->withdrawDiscount(Ulid::fromString($ruleId));
        $this->charges->charge($order);
        $this->transaction->commit();
    }
}
