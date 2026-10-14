<?php

declare(strict_types=1);

namespace App\Application\Order\GrantOrderDiscount;

use App\Application\Order\OrderCharges;
use App\Application\Transaction;
use App\Domain\Discount\DiscountRuleRepository;
use App\Domain\Order\OrderRepository;
use Symfony\Component\Uid\Ulid;

final readonly class GrantOrderDiscountHandler
{
    public function __construct(
        private OrderRepository $orders,
        private DiscountRuleRepository $rules,
        private OrderCharges $charges,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $orderId, string $ruleId): void
    {
        $order = $this->orders->get(Ulid::fromString($orderId));
        $order->grantDiscount($this->rules->get(Ulid::fromString($ruleId)));
        $this->charges->charge($order);
        $this->transaction->commit();
    }
}
