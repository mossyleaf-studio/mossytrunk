<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Identity\Workspace;
use App\Domain\Purchasing\Exception\DiscountExceedsLines;
use App\Domain\Purchasing\Exception\EmptySupplierOrder;
use App\Domain\Purchasing\Exception\NonPositiveExchangeRate;
use App\Domain\Purchasing\Exception\OrderedTwice;
use App\Domain\Purchasing\Exception\ReceivedQuantityMissing;
use App\Domain\Purchasing\Exception\SupplierOrderAlreadyReceived;
use App\Domain\Purchasing\Exception\SupplierOrdersNotMergeable;
use App\Domain\Purchasing\Exception\SupplierReferenceTooLong;
use App\Domain\Reference\Referenced;
use App\Domain\Reference\ReferenceSubject;
use App\Domain\Shared\CostAllocation;
use App\Domain\Shared\Exception\NegativeAmount;
use App\Domain\Shared\Money;
use App\Domain\Shared\OptionalText;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: 'supplier_order')]
#[ORM\UniqueConstraint(name: 'supplier_order_workspace_reference', columns: ['workspace_id', 'reference'])]
class SupplierOrder implements Referenced
{
    public const int SUPPLIER_REFERENCE_MAX_LENGTH = 100;
    public const int EURO_RATE = 1_000_000;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 64)]
    private string $reference;

    #[ORM\Column(length: self::SUPPLIER_REFERENCE_MAX_LENGTH, nullable: true)]
    private ?string $supplierReference = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Supplier $supplier;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $orderedOn;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'discount_')]
    private Money $discount;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'delivery_fees_')]
    private Money $deliveryFees;

    #[ORM\Column(length: 3, enumType: Currency::class, options: ['default' => 'EUR'])]
    private Currency $currency = Currency::Euro;

    #[ORM\Column(options: ['default' => self::EURO_RATE])]
    private int $exchangeRateMicros = self::EURO_RATE;

    #[ORM\Column(length: 16, enumType: SupplierOrderStatus::class)]
    private SupplierOrderStatus $status = SupplierOrderStatus::Ordered;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    /** @var Collection<int, SupplierOrderLine> */
    #[ORM\OneToMany(targetEntity: SupplierOrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /**
     * @param list<PurchasedItem> $items
     */
    private function __construct(string $reference, Supplier $supplier, \DateTimeImmutable $orderedOn, array $items, Money $discount, Money $deliveryFees)
    {
        $this->id = new Ulid();
        $this->workspace = $supplier->workspace();
        $this->reference = $reference;
        $this->lines = new ArrayCollection();
        $this->revise($supplier, $orderedOn, $items, $discount, $deliveryFees);
    }

    /**
     * @param list<PurchasedItem> $items
     */
    public static function place(string $reference, Supplier $supplier, \DateTimeImmutable $orderedOn, array $items, ?Money $discount = null, ?Money $deliveryFees = null): self
    {
        return new self($reference, $supplier, $orderedOn, $items, $discount ?? Money::zero(), $deliveryFees ?? Money::zero());
    }

    /**
     * @param list<PurchasedItem> $items
     */
    public function revise(Supplier $supplier, \DateTimeImmutable $orderedOn, array $items, ?Money $discount = null, ?Money $deliveryFees = null): void
    {
        if ([] === $items) {
            throw new EmptySupplierOrder();
        }
        $discount ??= Money::zero();
        $deliveryFees ??= Money::zero();
        if ($discount->isNegative()) {
            throw new NegativeAmount('overall_discount');
        }
        if ($deliveryFees->isNegative()) {
            throw new NegativeAmount('delivery_cost');
        }

        $this->supplier = $supplier;
        $this->orderedOn = $orderedOn;
        $this->lines->clear();
        foreach ($items as $position => $purchased) {
            if (null !== $this->lineFor($purchased->productId, $purchased->item->variant)) {
                throw new OrderedTwice($purchased->item->label());
            }
            $line = new SupplierOrderLine($this, $purchased, $position);
            if ($this->isReceived()) {
                $line->receive($purchased->received ?? throw new ReceivedQuantityMissing($line->label()));
            }
            $this->lines->add($line);
        }

        $this->allocate($discount, $deliveryFees);
    }

    public function absorb(self $other): void
    {
        $obstacle = $this->mergeObstacleWith($other);
        if (null !== $obstacle) {
            throw new SupplierOrdersNotMergeable($obstacle);
        }

        foreach ($other->lines() as $line) {
            $this->absorbLine($line);
        }
        $this->orderedOn = min($this->orderedOn, $other->orderedOn);
        $this->receivedAt = null === $this->receivedAt || null === $other->receivedAt ? $this->receivedAt : min($this->receivedAt, $other->receivedAt);
        $this->joinSupplierReference($other->supplierReference);
        $this->allocate($this->discount->add($other->discount), $this->deliveryFees->add($other->deliveryFees));
    }

    private function mergeObstacleWith(self $other): ?string
    {
        return match (true) {
            $other === $this || $other->id->equals($this->id) => 'same_order',
            !$other->supplier->id()->equals($this->supplier->id()) => 'different_supplier',
            $other->status !== $this->status => 'different_status',
            $other->currency !== $this->currency || $other->exchangeRateMicros !== $this->exchangeRateMicros => 'different_currency',
            default => null,
        };
    }

    private function absorbLine(SupplierOrderLine $line): void
    {
        $twin = $this->lineFor($line->productId(), $line->variant());
        if (null === $twin) {
            $this->lines->add($line->copyInto($this, $this->lines->count()));

            return;
        }
        $twin->absorb($line);
    }

    private function joinSupplierReference(?string $other): void
    {
        if (null === $other || $other === $this->supplierReference) {
            return;
        }
        $joined = null === $this->supplierReference ? $other : $this->supplierReference.', '.$other;
        if (mb_strlen($joined) <= self::SUPPLIER_REFERENCE_MAX_LENGTH) {
            $this->supplierReference = $joined;
        }
    }

    private function lineFor(Ulid $productId, ?string $variant): ?SupplierOrderLine
    {
        return $this->lines->findFirst(static fn (int $key, SupplierOrderLine $line): bool => $line->isFor($productId, $variant));
    }

    public function redateReception(\DateTimeImmutable $receivedAt): void
    {
        if ($this->isReceived()) {
            $this->receivedAt = $receivedAt;
        }
    }

    public function isReceived(): bool
    {
        return SupplierOrderStatus::Received === $this->status;
    }

    public function priceIn(Currency $currency, int $exchangeRateMicros = self::EURO_RATE): void
    {
        if (Currency::Euro === $currency) {
            $exchangeRateMicros = self::EURO_RATE;
        }
        if ($exchangeRateMicros < 1) {
            throw new NonPositiveExchangeRate();
        }

        $this->currency = $currency;
        $this->exchangeRateMicros = $exchangeRateMicros;
    }

    public function inEuros(Money $amount): Money
    {
        return $amount->prorate($this->exchangeRateMicros, self::EURO_RATE);
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function exchangeRateMicros(): int
    {
        return $this->exchangeRateMicros;
    }

    public function referToSupplierOrder(?string $supplierReference): void
    {
        $supplierReference = OptionalText::of($supplierReference);
        if (null !== $supplierReference && mb_strlen($supplierReference) > self::SUPPLIER_REFERENCE_MAX_LENGTH) {
            throw new SupplierReferenceTooLong(self::SUPPLIER_REFERENCE_MAX_LENGTH);
        }

        $this->supplierReference = $supplierReference;
    }

    private function allocate(Money $discount, Money $deliveryFees): void
    {
        $lines = $this->lines();
        if ($discount->greaterThan($this->subtotal())) {
            throw new DiscountExceedsLines();
        }

        $this->discount = $discount;
        $this->deliveryFees = $deliveryFees;
        $discounts = CostAllocation::proportionally($discount, array_map(static fn (SupplierOrderLine $line): Money => $line->totalPrice(), $lines));
        $fees = CostAllocation::equally($deliveryFees, \count($lines));
        foreach ($lines as $index => $line) {
            $line->share($discounts[$index], $fees[$index]);
        }
    }

    /**
     * @param array<string, int> $receivedQuantities
     *
     * @return list<SupplierOrderLine>
     */
    public function receive(array $receivedQuantities, \DateTimeImmutable $receivedAt): array
    {
        $this->assertStillOrdered();

        foreach ($this->lines() as $line) {
            $quantity = $receivedQuantities[(string) $line->id()] ?? throw new ReceivedQuantityMissing($line->label());
            $line->receive($quantity);
        }
        $this->status = SupplierOrderStatus::Received;
        $this->receivedAt = $receivedAt;

        return array_values(array_filter($this->lines(), static fn (SupplierOrderLine $line): bool => $line->receivedQuantity() > 0));
    }

    public function assertStillOrdered(): void
    {
        if ($this->isReceived()) {
            throw new SupplierOrderAlreadyReceived($this->reference);
        }
    }

    public function subtotal(): Money
    {
        return Money::sum(array_map(static fn (SupplierOrderLine $line): Money => $line->totalPrice(), $this->lines()));
    }

    public function total(): Money
    {
        return $this->subtotal()->subtract($this->discount)->add($this->deliveryFees);
    }

    public function discount(): Money
    {
        return $this->discount;
    }

    public function deliveryFees(): Money
    {
        return $this->deliveryFees;
    }

    public function orderedUnits(): int
    {
        return array_sum(array_map(static fn (SupplierOrderLine $line): int => $line->orderedQuantity(), $this->lines()));
    }

    public function receivedUnits(): ?int
    {
        return SupplierOrderStatus::Received === $this->status
            ? array_sum(array_map(static fn (SupplierOrderLine $line): int => (int) $line->receivedQuantity(), $this->lines()))
            : null;
    }

    public function id(): Ulid
    {
        return $this->id;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function referenceSubject(): ReferenceSubject
    {
        return ReferenceSubject::at($this->orderedOn);
    }

    public function changeReference(string $reference): void
    {
        $this->reference = $reference;
    }

    public function supplierReference(): ?string
    {
        return $this->supplierReference;
    }

    public function supplier(): Supplier
    {
        return $this->supplier;
    }

    public function orderedOn(): \DateTimeImmutable
    {
        return $this->orderedOn;
    }

    public function status(): SupplierOrderStatus
    {
        return $this->status;
    }

    public function receivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    /**
     * @return list<SupplierOrderLine>
     */
    public function lines(): array
    {
        $lines = array_values($this->lines->toArray());
        usort($lines, static fn (SupplierOrderLine $a, SupplierOrderLine $b): int => $a->position() <=> $b->position());

        return $lines;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }
}
