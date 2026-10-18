<?php

declare(strict_types=1);

namespace App\Application\Order\AnnotateOrder;

use App\Application\Transaction;
use App\Domain\Order\OrderRepository;
use Symfony\Component\Uid\Ulid;

final readonly class AnnotateOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $orderId, ?string $note): void
    {
        $this->orders->get(Ulid::fromString($orderId))->annotate($note);
        $this->transaction->commit();
    }
}
