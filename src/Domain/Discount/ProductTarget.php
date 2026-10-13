<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use App\Domain\Product\Exception\UnknownVariant;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
class ProductTarget extends ConditionTarget
{
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private Product $product;

    public function __construct(DiscountCondition $condition, Product $product, ?string $variant = null)
    {
        parent::__construct($condition);
        $this->retarget($product, $variant);
    }

    public function retarget(Product $product, ?string $variant): void
    {
        $this->product = $product;
        $this->variant = null === $variant ? null : ($product->variantNamed($variant) ?? throw new UnknownVariant($product->displayName(), $variant));
    }

    public function subject(): Product
    {
        return $this->product;
    }

    public function concerns(ProductType $type): bool
    {
        return $this->product->type() === $type;
    }

    public function kind(): string
    {
        return 'product';
    }

    protected function matchesSubject(Ulid $productId, Ulid $typeId): bool
    {
        return $this->product->id()->equals($productId);
    }

    protected function specificityOfSubject(): int
    {
        return 3;
    }

    protected function nameOfSubject(): string
    {
        return $this->product->displayName();
    }
}
