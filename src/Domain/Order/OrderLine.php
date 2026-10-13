<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exception\InvalidOrderQuantity;
use App\Domain\Order\Exception\LineAlreadyIdentified;
use App\Domain\Order\Exception\UnknownProductChosen;
use App\Domain\Product\SellableItem;
use App\Domain\Product\VariantLabel;
use App\Domain\Shared\Money;
use App\Domain\Stock\LotRevision;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'order_line')]
#[ORM\Index(name: 'order_line_product_idx', columns: ['product_id'])]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(type: UlidType::NAME, nullable: true)]
    private ?Ulid $productId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $variant;

    #[ORM\Column(length: 255)]
    private string $productName;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'unit_price_')]
    private Money $unitPrice;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'cost_')]
    private Money $cost;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'discount_')]
    private Money $discount;

    public function __construct(Order $order, SellableItem $item, int $quantity, Money $cost)
    {
        if ($quantity < 1) {
            throw new InvalidOrderQuantity();
        }

        $this->id = new Ulid();
        $this->order = $order;
        $this->productId = $item->productId;
        $this->variant = $item->variant;
        $this->productName = $item->productName;
        $this->unitPrice = $item->sellingPrice;
        $this->cost = $cost;
        $this->quantity = $quantity;
        $this->discount = Money::zero();
    }

    public function isFor(Ulid $productId, ?string $variant): bool
    {
        return ($this->productId?->equals($productId) ?? false) && $this->variant === $variant;
    }

    public function sells(SellableItem $item): bool
    {
        return null !== $item->productId && $this->isFor($item->productId, $item->variant);
    }

    public function reassign(SellableItem $item): void
    {
        $this->productId = $item->productId;
        $this->variant = $item->variant;
        $this->productName = $item->productName;
    }

    public function copyInto(Order $order): self
    {
        $copy = clone $this;
        $copy->id = new Ulid();
        $copy->order = $order;

        return $copy;
    }

    public function sellsSameAs(self $other): bool
    {
        return null !== $other->productId && $this->isFor($other->productId, $other->variant);
    }

    public function identify(SellableItem $item, Money $cost): void
    {
        if (!$this->sellsUnknownProduct()) {
            throw new LineAlreadyIdentified($this->label());
        }
        if (!$item->isKnown()) {
            throw new UnknownProductChosen();
        }

        $this->reassign($item);
        $this->cost = $cost;
    }

    public function hasUnknownCost(): bool
    {
        return null !== $this->productId && $this->cost->isZero();
    }

    public function costAt(Money $unitCost): void
    {
        $this->cost = $unitCost->multiply($this->quantity);
    }

    public function followLot(LotRevision $revision): bool
    {
        if (!$revision->drewFrom($this->quantity, $this->cost)) {
            return false;
        }
        $this->cost = $revision->costOf($this->quantity);

        return true;
    }

    public function raiseTo(Money $unitPrice): Money
    {
        if (!$unitPrice->greaterThan($this->unitPrice)) {
            return Money::zero();
        }
        $raise = $unitPrice->subtract($this->unitPrice)->multiply($this->quantity);
        $this->unitPrice = $unitPrice;

        return $raise;
    }

    public function sameUnitAmountsAs(self $other): bool
    {
        return $this->unitPrice->equals($other->unitPrice);
    }

    public function add(int $quantity, Money $cost): void
    {
        if ($quantity < 1) {
            throw new InvalidOrderQuantity();
        }

        $this->quantity += $quantity;
        $this->cost = $this->cost->add($cost);
    }

    public function total(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function cost(): Money
    {
        return $this->cost;
    }

    public function shareDiscount(Money $discount): void
    {
        $this->discount = $discount;
    }

    public function discount(): Money
    {
        return $this->discount;
    }

    public function revenue(): Money
    {
        return $this->total()->subtract($this->discount);
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function productId(): ?Ulid
    {
        return $this->productId;
    }

    public function sellsUnknownProduct(): bool
    {
        return null === $this->productId;
    }

    public function variant(): ?string
    {
        return $this->variant;
    }

    public function productName(): string
    {
        return $this->productName;
    }

    public function label(): string
    {
        return VariantLabel::display($this->productName, $this->variant);
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function unitCost(): Money
    {
        return $this->cost->prorate(1, $this->quantity);
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}
