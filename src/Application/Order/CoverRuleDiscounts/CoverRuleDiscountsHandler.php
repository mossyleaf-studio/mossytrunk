<?php

declare(strict_types=1);

namespace App\Application\Order\CoverRuleDiscounts;

use App\Application\Order\OrderPricing;
use App\Application\Transaction;
use App\Domain\Discount\AppliedDiscount;
use App\Domain\Discount\DiscountRule;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\OrderLine;
use App\Domain\Order\OrderRepository;
use App\Domain\Product\ProductRepository;
use App\Domain\Product\SellableItem;
use App\Domain\Shared\Money;

final readonly class CoverRuleDiscountsHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ProductRepository $products,
        private OrderPricing $pricing,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(): int
    {
        $rules = $this->pricing->rules();
        $covered = 0;
        foreach ($this->orders->withRuleDiscounts() as $order) {
            if (array_any($order->appliedDiscounts(), static fn (AppliedDiscount $discount): bool => $discount->awaitsItsCovers())
                && $order->coverDiscounts($this->pricing->discounts($this->itemsOf($order), $order->placedAt(), self::rulesAppliedTo($order, $rules)))) {
                ++$covered;
            }
        }
        $this->transaction->commit();

        return $covered;
    }

    /**
     * @return list<OrderedItem>
     */
    private function itemsOf(Order $order): array
    {
        $products = $this->products->findByIds(array_values(array_filter(array_map(static fn (OrderLine $line) => $line->productId(), $order->lines()))));
        $items = [];
        foreach ($order->lines() as $line) {
            $product = $products[(string) $line->productId()] ?? null;
            if (null !== $product) {
                $items[] = new OrderedItem(new SellableItem($product->id(), $line->variant(), $line->productName(), $line->unitPrice(), Money::zero(), $product->type()->id()), $line->quantity());
            }
        }

        return $items;
    }

    /**
     * @param list<DiscountRule> $rules
     *
     * @return list<DiscountRule>
     */
    private static function rulesAppliedTo(Order $order, array $rules): array
    {
        $applied = array_map(static fn (AppliedDiscount $discount): string => (string) $discount->ruleId, $order->appliedDiscounts());

        return array_values(array_filter($rules, static fn (DiscountRule $rule): bool => \in_array((string) $rule->id(), $applied, true)));
    }
}
