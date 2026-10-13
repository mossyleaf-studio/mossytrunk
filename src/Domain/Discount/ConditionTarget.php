<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use App\Domain\Design\DesignCollection;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use App\Domain\Product\VariantLabel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'discount_condition_target')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['product' => ProductTarget::class, 'type' => TypeTarget::class])]
abstract class ConditionTarget
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: DiscountCondition::class, inversedBy: 'targets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DiscountCondition $condition;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $variant = null;

    #[ORM\ManyToOne(targetEntity: DesignCollection::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?DesignCollection $collection = null;

    protected function __construct(DiscountCondition $condition)
    {
        $this->id = new Ulid();
        $this->condition = $condition;
    }

    public static function of(DiscountCondition $condition, TargetSpec $spec): self
    {
        return $spec->target instanceof Product
            ? new ProductTarget($condition, $spec->target, $spec->variant)
            : new TypeTarget($condition, $spec->target, $spec->variant, $spec->collection);
    }

    abstract protected function matchesSubject(Ulid $productId, Ulid $typeId): bool;

    abstract protected function specificityOfSubject(): int;

    abstract protected function nameOfSubject(): string;

    abstract public function subject(): Product|ProductType;

    abstract public function concerns(ProductType $type): bool;

    abstract public function kind(): string;

    public function matches(Ulid $productId, Ulid $typeId, ?string $variant, ?Ulid $collectionId = null): bool
    {
        return $this->matchesSubject($productId, $typeId)
            && (null === $this->variant || VariantLabel::same($this->variant, $variant))
            && (null === $this->collection || true === $collectionId?->equals($this->collection->id()));
    }

    public function concernsProduct(Product $product): bool
    {
        return $this->matchesSubject($product->id(), $product->type()->id());
    }

    public function is(Product|ProductType $subject, ?string $variant, ?DesignCollection $collection = null): bool
    {
        return $subject === $this->subject() && VariantLabel::same($this->variant, $variant) && $collection === $this->collection;
    }

    public function specificity(): int
    {
        return $this->specificityOfSubject() + (null === $this->variant ? 0 : 1) + (null === $this->collection ? 0 : 1);
    }

    public function name(): string
    {
        return implode(' · ', array_filter([$this->nameOfSubject(), $this->collection?->name(), $this->variant], static fn (?string $part): bool => null !== $part));
    }

    public function isIn(DesignCollection $collection): bool
    {
        return $collection === $this->collection;
    }

    public function renameVariant(string $from, string $to): void
    {
        if (VariantLabel::same($this->variant, $from)) {
            $this->variant = $to;
        }
    }

    public function subjectId(): Ulid
    {
        return $this->subject()->id();
    }

    public function variant(): ?string
    {
        return $this->variant;
    }

    public function collection(): ?DesignCollection
    {
        return $this->collection;
    }
}
