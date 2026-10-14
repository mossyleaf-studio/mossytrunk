<?php

declare(strict_types=1);

namespace App\Application\Order\SplitOrderLine;

use App\Application\Integration\Connectors;
use App\Application\Order\OrderPricing;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Application\Translator;
use App\Domain\Order\OrderRepository;
use Symfony\Component\Uid\Ulid;

final readonly class SplitOrderLineHandler
{
    public function __construct(
        private OrderRepository $orders,
        private OrderPricing $pricing,
        private StockKeeper $stock,
        private Connectors $connectors,
        private Translator $translator,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(SplitOrderLine $command): void
    {
        $order = $this->orders->get(Ulid::fromString($command->orderId));
        $line = $order->lineToIdentify(Ulid::fromString($command->lineId));
        $items = $this->stock->withdraw($order->event(), $this->pricing->items($command->lines, $order->placedAt(), $order->channel()));
        $ruleDiscounts = null === $order->event() ? [] : $this->pricing->discounts($items, $order->placedAt());
        $service = $this->connectors->get($order->source())->describe()->label;

        $order->splitLine($line->id(), $items, $ruleDiscounts, $this->translator->trans('import.discount', ['service' => $service]));
        $this->transaction->commit();
    }
}
