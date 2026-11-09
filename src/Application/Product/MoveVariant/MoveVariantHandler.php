<?php

declare(strict_types=1);

namespace App\Application\Product\MoveVariant;

use App\Application\Product\NewProducts;
use App\Application\Stock\StockKeeper;
use App\Application\Transaction;
use App\Domain\Discount\DiscountRuleRepository;
use App\Domain\Integration\ExternalItemRepository;
use App\Domain\Order\OrderRepository;
use App\Domain\Product\Exception\SoldWithoutVariant;
use App\Domain\Product\Exception\VariantMovedOntoItself;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Shared\OptionalText;
use App\Domain\Stock\StockRepository;
use Symfony\Component\Uid\Ulid;

final readonly class MoveVariantHandler
{
    public function __construct(
        private ProductRepository $products,
        private OrderRepository $orders,
        private DiscountRuleRepository $discountRules,
        private ExternalItemRepository $externalItems,
        private NewProducts $newProducts,
        private StockKeeper $stock,
        private StockRepository $stockItems,
        private MovedSales $movedSales,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(MoveVariant $command): Ulid
    {
        $source = $this->products->get(Ulid::fromString($command->productId));
        $variant = OptionalText::of($command->variant);
        $source->sellable($variant);

        $target = $this->targetOf($command, $source);
        $targetVariant = OptionalText::of($command->targetVariant);
        $this->offerVariant($target, $targetVariant);
        $to = $target->sellable($targetVariant);
        $unitCost = $this->stockItems->find($target->id(), $targetVariant)?->firstPurchaseUnitCost() ?? $target->buyingPrice();

        foreach ($this->orders->selling($source->id()) as $order) {
            $this->movedSales->move($order, $source->id(), $variant, $target, $targetVariant, $unitCost);
        }
        $this->stock->move($source, $variant, $target, $targetVariant);
        foreach ($this->externalItems->linkedTo($source->id()) as $item) {
            $item->relink($source->id(), $variant, $to);
        }
        $this->retire($source, $variant, $target, $targetVariant);

        $this->transaction->commit();

        return $target->id();
    }

    private function targetOf(MoveVariant $command, Product $source): Product
    {
        $target = null === $command->targetProductId ? $this->newProductLike($source, (string) $command->newProductName) : $this->products->get(Ulid::fromString($command->targetProductId));
        if ($target === $source) {
            throw new VariantMovedOntoItself();
        }

        return $target;
    }

    private function offerVariant(Product $target, ?string $targetVariant): void
    {
        if (null === $targetVariant || $target->hasVariant($targetVariant)) {
            return;
        }
        if (!$target->hasVariants() && $this->orders->sells($target->id())) {
            throw new SoldWithoutVariant($target->displayName());
        }
        $target->addVariant($targetVariant);
    }

    private function retire(Product $source, ?string $variant, Product $target, ?string $targetVariant): void
    {
        if (null !== $variant) {
            $source->removeVariant($variant);
        }
        if (null !== $variant && $source->hasVariants()) {
            return;
        }
        foreach ($this->discountRules->all() as $rule) {
            $rule->replaceProduct($source, $variant, $target, $targetVariant);
        }
        $this->products->remove($source);
    }

    private function newProductLike(Product $source, string $name): Product
    {
        $product = $this->newProducts->create($name, $source->sellingPrice(), $source->type());
        $product->bought($source->buyingPrice());

        return $product;
    }
}
