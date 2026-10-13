<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use App\Domain\Design\DesignCollection;
use App\Domain\Discount\Exception\ConditionQuantityTooSmall;
use App\Domain\Discount\Exception\ConditionWithoutTarget;
use App\Domain\Discount\Exception\DuplicateConditionTarget;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use App\Domain\Product\VariantLabel;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'discount_condition')]
class DiscountCondition
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: DiscountRule::class, inversedBy: 'conditions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DiscountRule $rule;

    #[ORM\Column]
    private int $quantity;

    /** @var Collection<int, ConditionTarget> */
    #[ORM\OneToMany(targetEntity: ConditionTarget::class, mappedBy: 'condition', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $targets;

    /**
     * @param list<TargetSpec> $targets
     */
    public function __construct(DiscountRule $rule, int $quantity, array $targets)
    {
        if ($quantity < 1) {
            throw new ConditionQuantityTooSmall();
        }
        if ([] === $targets) {
            throw new ConditionWithoutTarget();
        }
        $this->id = new Ulid();
        $this->rule = $rule;
        $this->quantity = $quantity;
        $this->targets = new ArrayCollection();
        foreach ($targets as $spec) {
            $target = ConditionTarget::of($this, $spec);
            if ($this->has($target->subject(), $target->variant(), $target->collection())) {
                throw new DuplicateConditionTarget($target->name());
            }
            $this->targets->add($target);
        }
    }

    public function matches(Ulid $productId, Ulid $typeId, ?string $variant, ?Ulid $collectionId = null): bool
    {
        return $this->targets->exists(static fn (int $key, ConditionTarget $target): bool => $target->matches($productId, $typeId, $variant, $collectionId));
    }

    public function concernsProduct(Product $product): bool
    {
        return $this->targets->exists(static fn (int $key, ConditionTarget $target): bool => $target->concernsProduct($product));
    }

    public function has(Product|ProductType $subject, ?string $variant, ?DesignCollection $collection = null): bool
    {
        return $this->targets->exists(static fn (int $key, ConditionTarget $target): bool => $target->is($subject, $variant, $collection));
    }

    public function filtersByCollection(): bool
    {
        return $this->targets->exists(static fn (int $key, ConditionTarget $target): bool => null !== $target->collection());
    }

    public function onlyTargetsCollection(DesignCollection $collection): bool
    {
        return array_all($this->targets(), static fn (ConditionTarget $target): bool => $target->isIn($collection));
    }

    public function withdrawCollection(DesignCollection $collection): void
    {
        foreach ($this->targets() as $target) {
            if ($target->isIn($collection)) {
                $this->targets->removeElement($target);
            }
        }
    }

    public function specificity(): int
    {
        return array_reduce($this->targets(), static fn (?int $least, ConditionTarget $target): int => min($least ?? $target->specificity(), $target->specificity())) ?? 0;
    }

    /**
     * @return list<ConditionTarget>
     */
    public function targets(): array
    {
        return array_values($this->targets->toArray());
    }

    /**
     * @return list<ProductTarget>
     */
    public function targetsOn(Product $product): array
    {
        return array_values(array_filter($this->targets(), static fn (ConditionTarget $target): bool => $target instanceof ProductTarget && $target->subject() === $product));
    }

    public function onlyTargets(Product|ProductType $subject): bool
    {
        return array_all($this->targets(), static fn (ConditionTarget $target): bool => $target->subject() === $subject);
    }

    public function withdraw(Product|ProductType $subject): void
    {
        foreach ($this->targets() as $target) {
            if ($target->subject() === $subject) {
                $this->targets->removeElement($target);
            }
        }
    }

    public function drop(ConditionTarget $target): void
    {
        $this->targets->removeElement($target);
    }

    public function isEmpty(): bool
    {
        return $this->targets->isEmpty();
    }

    public function renameVariant(ProductType $type, string $from, string $to): void
    {
        foreach ($this->targets as $target) {
            if ($target->concerns($type)) {
                $target->renameVariant($from, $to);
            }
        }
    }

    public function usesVariant(ProductType $type, string $variant): bool
    {
        return $this->targets->exists(static fn (int $key, ConditionTarget $target): bool => $target->concerns($type) && VariantLabel::same($target->variant(), $variant));
    }

    public function name(): string
    {
        return implode(' / ', array_map(static fn (ConditionTarget $target): string => $target->name(), $this->targets()));
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function add(int $quantity): void
    {
        $this->quantity += $quantity;
    }
}
