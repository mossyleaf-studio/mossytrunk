<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use App\Domain\Design\DesignCollection;
use App\Domain\Product\Exception\UnknownTypeVariant;
use App\Domain\Product\ProductType;
use App\Domain\Product\VariantLabel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
class TypeTarget extends ConditionTarget
{
    #[ORM\ManyToOne(targetEntity: ProductType::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ProductType $type;

    public function __construct(DiscountCondition $condition, ProductType $type, ?string $variant = null, ?DesignCollection $collection = null)
    {
        parent::__construct($condition);
        $this->type = $type;
        $this->collection = $collection;
        $this->variant = null === $variant ? null : (VariantLabel::find($type->variants(), $variant) ?? throw new UnknownTypeVariant($type->name(), $variant));
    }

    public function subject(): ProductType
    {
        return $this->type;
    }

    public function concerns(ProductType $type): bool
    {
        return $this->type === $type;
    }

    public function kind(): string
    {
        return 'type';
    }

    protected function matchesSubject(Ulid $productId, Ulid $typeId): bool
    {
        return $this->type->id()->equals($typeId);
    }

    protected function specificityOfSubject(): int
    {
        return 0;
    }

    protected function nameOfSubject(): string
    {
        return $this->type->name();
    }
}
