<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Domain\Design\DesignCollectionRepository;
use App\Domain\Discount\AppliedDiscount;
use App\Domain\Discount\BasketLine;
use App\Domain\Discount\DiscountCalculator;
use App\Domain\Discount\DiscountRule;
use App\Domain\Discount\DiscountRuleRepository;
use App\Domain\Order\Exception\InvalidOrderQuantity;
use App\Domain\Order\OrderedItem;
use App\Domain\Product\ProductRepository;
use Symfony\Component\Uid\Ulid;

final readonly class OrderPricing
{
    public function __construct(
        private ProductRepository $products,
        private DiscountRuleRepository $discountRules,
        private DiscountCalculator $calculator,
        private DesignCollectionRepository $collections,
    ) {
    }

    /**
     * @param list<RequestedLine> $lines
     *
     * @return list<OrderedItem>
     */
    public function items(array $lines, \DateTimeImmutable $placedAt): array
    {
        return array_map(function (RequestedLine $line) use ($placedAt): OrderedItem {
            if ($line->quantity < 1) {
                throw new InvalidOrderQuantity();
            }

            $product = $this->products->get(Ulid::fromString($line->productId));

            return new OrderedItem($product->sellableOn(null, $line->variant, $placedAt), $line->quantity);
        }, $lines);
    }

    /**
     * @return list<DiscountRule>
     */
    public function rules(): array
    {
        return $this->discountRules->all();
    }

    /**
     * @param list<OrderedItem>       $items
     * @param list<DiscountRule>|null $rules
     *
     * @return list<AppliedDiscount>
     */
    public function discounts(array $items, \DateTimeImmutable $placedAt, ?array $rules = null): array
    {
        $rules ??= $this->rules();
        $collections = array_any($rules, static fn (DiscountRule $rule): bool => $rule->filtersByCollection()) ? $this->collections->byProduct() : [];
        $basket = array_values(array_filter(array_map(
            static fn (OrderedItem $ordered): ?BasketLine => BasketLine::of($ordered->item, $ordered->quantity, ($collections[(string) $ordered->item->productId] ?? null)?->id()),
            $items,
        )));

        return $this->calculator->calculate($basket, $rules, $placedAt);
    }
}
