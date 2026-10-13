<?php

declare(strict_types=1);

namespace App\Application\Product\MoveVariant;

use App\Application\Integration\Connectors;
use App\Application\Integration\LinePrices;
use App\Application\Translator;
use App\Domain\Order\Order;
use App\Domain\Product\Product;
use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final readonly class MovedSales
{
    public function __construct(
        private Connectors $connectors,
        private Translator $translator,
    ) {
    }

    public function move(Order $order, Ulid $productId, ?string $variant, Product $target, ?string $targetVariant, ?Money $unitCost): void
    {
        $order->moveSales(
            $productId,
            $variant,
            $target->sellableOn($order->channel(), $targetVariant, $order->placedAt()),
            $unitCost,
            $this->raisedPricesDiscount($order),
        );
    }

    private function raisedPricesDiscount(Order $order): ?string
    {
        if (!$this->connectors->has($order->source()) || LinePrices::MayBeDiscounted !== $this->connectors->get($order->source())->describe()->linePrices) {
            return null;
        }

        return $this->translator->trans('import.discount', ['service' => $this->connectors->labelOf($order->source())]);
    }
}
