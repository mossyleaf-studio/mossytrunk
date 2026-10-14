<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use App\Domain\Design\DesignCollection;
use App\Domain\Discount\Exception\DiscountExpired;
use App\Domain\Discount\Exception\DiscountNotRunning;
use App\Domain\Discount\Exception\DiscountNotValidOn;
use App\Domain\Discount\Exception\DuplicateConditionTarget;
use App\Domain\Discount\Exception\EmptyDiscountName;
use App\Domain\Discount\Exception\NoDiscountCondition;
use App\Domain\Discount\Exception\NotAManualDiscount;
use App\Domain\Discount\Exception\OnlyEligibleCollection;
use App\Domain\Discount\Exception\OnlyEligibleProduct;
use App\Domain\Discount\Exception\OnlyEligibleType;
use App\Domain\Identity\Workspace;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use App\Domain\Product\VariantLabel;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'discount_rule')]
class DiscountRule
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 255)]
    private string $name;

    /** @var Collection<int, DiscountCondition> */
    #[ORM\OneToMany(targetEntity: DiscountCondition::class, mappedBy: 'rule', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $conditions;

    #[ORM\Embedded(class: DiscountAction::class, columnPrefix: 'action_')]
    private DiscountAction $action;

    #[ORM\Embedded(class: ValidityPeriod::class, columnPrefix: 'valid_')]
    private ValidityPeriod $validity;

    #[ORM\Column(options: ['default' => false])]
    private bool $manual = false;

    /**
     * @param list<ConditionSpec> $conditions
     */
    private function __construct(Ulid $id, Workspace $workspace, string $name, array $conditions, DiscountAction $action, ValidityPeriod $validity, bool $manual)
    {
        $this->id = $id;
        $this->workspace = $workspace;
        $this->conditions = new ArrayCollection();
        $this->redefine($name, $conditions, $action, $validity, $manual);
    }

    /**
     * @param list<ConditionSpec> $conditions
     */
    public static function create(Workspace $workspace, string $name, array $conditions, DiscountAction $action, ?ValidityPeriod $validity = null, bool $manual = false): self
    {
        return new self(new Ulid(), $workspace, $name, $conditions, $action, $validity ?? ValidityPeriod::always(), $manual);
    }

    /**
     * @param list<ConditionSpec> $conditions
     */
    public function redefine(string $name, array $conditions, DiscountAction $action, ?ValidityPeriod $validity = null, bool $manual = false): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new EmptyDiscountName();
        }
        $conditions = $manual ? [] : $conditions;
        if (!$manual && [] === $conditions) {
            throw new NoDiscountCondition();
        }

        $built = [];
        foreach ($conditions as $spec) {
            $condition = new DiscountCondition($this, $spec->quantity, $spec->targets);
            foreach ($condition->targets() as $target) {
                if (array_any($built, static fn (DiscountCondition $other): bool => $other->has($target->subject(), $target->variant(), $target->collection()))) {
                    throw new DuplicateConditionTarget($target->name());
                }
            }
            $built[] = $condition;
        }

        $this->name = $name;
        $this->action = $action;
        $this->validity = $validity ?? ValidityPeriod::always();
        $this->manual = $manual;
        $this->conditions->clear();
        foreach ($built as $condition) {
            $this->conditions->add($condition);
        }
    }

    public function replaceProduct(Product $replaced, ?string $movedVariant, Product $by, ?string $targetVariant): void
    {
        if ($this->manual) {
            return;
        }
        foreach ($this->conditions() as $condition) {
            foreach ($condition->targetsOn($replaced) as $source) {
                $this->move($condition, $source, $movedVariant, $by, $targetVariant);
            }
            if ($condition->isEmpty()) {
                $this->conditions->removeElement($condition);
            }
        }
        if ($this->conditions->isEmpty()) {
            throw new OnlyEligibleProduct($this->name, $replaced->displayName());
        }
    }

    public function withdrawProduct(Product $product): void
    {
        if (!$this->manual && $this->onlyTargets($product)) {
            throw new OnlyEligibleProduct($this->name, $product->displayName());
        }
        $this->withdraw($product);
    }

    public function withdrawType(ProductType $type): void
    {
        if (!$this->manual && $this->onlyTargets($type)) {
            throw new OnlyEligibleType($this->name, $type->name());
        }
        $this->withdraw($type);
    }

    public function withdrawCollection(DesignCollection $collection): void
    {
        if (!$this->manual && $this->conditions->forAll(static fn (int $key, DiscountCondition $condition): bool => $condition->onlyTargetsCollection($collection))) {
            throw new OnlyEligibleCollection($this->name, $collection->name());
        }
        foreach ($this->conditions() as $condition) {
            $condition->withdrawCollection($collection);
            if ($condition->isEmpty()) {
                $this->conditions->removeElement($condition);
            }
        }
    }

    public function filtersByCollection(): bool
    {
        return $this->conditions->exists(static fn (int $key, DiscountCondition $condition): bool => $condition->filtersByCollection());
    }

    public function renameVariant(ProductType $type, string $from, string $to): void
    {
        foreach ($this->conditions as $condition) {
            $condition->renameVariant($type, $from, $to);
        }
    }

    public function usesVariant(ProductType $type, string $variant): bool
    {
        return $this->conditions->exists(static fn (int $key, DiscountCondition $condition): bool => $condition->usesVariant($type, $variant));
    }

    public function startOn(\DateTimeImmutable $today): void
    {
        match ($this->statusOn($today)) {
            DiscountStatus::Expired => throw new DiscountExpired($this->name),
            DiscountStatus::Upcoming => $this->validity = $this->validity->startingOn($today),
            DiscountStatus::Running => null,
        };
    }

    public function stopBefore(\DateTimeImmutable $today): void
    {
        if (DiscountStatus::Running !== $this->statusOn($today)) {
            throw new DiscountNotRunning($this->name);
        }

        $this->validity = $this->validity->endingBefore($today);
    }

    public function statusOn(\DateTimeImmutable $moment): DiscountStatus
    {
        return $this->validity->statusOn($moment);
    }

    public function concerns(Product $product): bool
    {
        return $this->conditions->exists(static fn (int $key, DiscountCondition $condition): bool => $condition->concernsProduct($product));
    }

    public function appliesOn(\DateTimeImmutable $moment): bool
    {
        return $this->validity->covers($moment);
    }

    public function appliesAutomaticallyOn(\DateTimeImmutable $moment): bool
    {
        return !$this->manual && $this->appliesOn($moment);
    }

    public function grantOn(\DateTimeImmutable $placedAt): AppliedDiscount
    {
        if (!$this->manual) {
            throw new NotAManualDiscount($this->name);
        }
        if (!$this->appliesOn($placedAt)) {
            throw new DiscountNotValidOn($this->name, $placedAt);
        }

        return AppliedDiscount::granted($this->name, $this->id, $this->action);
    }

    public function isManual(): bool
    {
        return $this->manual;
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<DiscountCondition>
     */
    public function conditions(): array
    {
        return array_values($this->conditions->toArray());
    }

    /**
     * @return list<DiscountCondition>
     */
    public function conditionsMostSpecificFirst(): array
    {
        $conditions = $this->conditions();
        usort($conditions, static fn (DiscountCondition $a, DiscountCondition $b): int => $b->specificity() <=> $a->specificity());

        return $conditions;
    }

    public function action(): DiscountAction
    {
        return $this->action;
    }

    public function validity(): ValidityPeriod
    {
        return $this->validity;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    private function move(DiscountCondition $condition, ProductTarget $source, ?string $movedVariant, Product $by, ?string $targetVariant): void
    {
        $variant = null === $source->variant() || VariantLabel::same($source->variant(), $movedVariant) ? $targetVariant : $by->variantNamed($source->variant());
        if (null !== $source->variant() && null === $variant) {
            $condition->drop($source);

            return;
        }

        $existing = $this->conditionOn($by, $variant);
        if (null === $existing) {
            $source->retarget($by, $variant);

            return;
        }
        $condition->drop($source);
        if ($existing !== $condition && $condition->isEmpty()) {
            $existing->add($condition->quantity());
        }
    }

    private function conditionOn(Product|ProductType $subject, ?string $variant): ?DiscountCondition
    {
        return $this->conditions->findFirst(static fn (int $key, DiscountCondition $condition): bool => $condition->has($subject, $variant));
    }

    private function onlyTargets(Product|ProductType $subject): bool
    {
        return $this->conditions->forAll(static fn (int $key, DiscountCondition $condition): bool => $condition->onlyTargets($subject));
    }

    private function withdraw(Product|ProductType $subject): void
    {
        foreach ($this->conditions() as $condition) {
            $condition->withdraw($subject);
            if ($condition->isEmpty()) {
                $this->conditions->removeElement($condition);
            }
        }
    }
}
