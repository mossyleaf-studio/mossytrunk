<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Discount\AppliedDiscount;
use App\Domain\Discount\DiscountRule;
use App\Domain\Event\Event;
use App\Domain\Identity\Workspace;
use App\Domain\Order\Exception\DiscountAlreadyGranted;
use App\Domain\Order\Exception\DiscountExceedsSubtotal;
use App\Domain\Order\Exception\EmptyOrder;
use App\Domain\Order\Exception\NegativePostage;
use App\Domain\Order\Exception\NegativeShippingCost;
use App\Domain\Order\Exception\OrderAlreadyRefunded;
use App\Domain\Order\Exception\OrderOutsideEvent;
use App\Domain\Order\Exception\OrdersNotMergeable;
use App\Domain\Order\Exception\RefundedOrderLocked;
use App\Domain\Order\Exception\SupplyNotOnChannel;
use App\Domain\Product\SellableItem;
use App\Domain\Reference\Referenced;
use App\Domain\Reference\ReferenceSubject;
use App\Domain\Sales\Exception\MarketOrderWithoutEvent;
use App\Domain\Sales\OrderCharge;
use App\Domain\Sales\SalesChannel;
use App\Domain\Shared\CostAllocation;
use App\Domain\Shared\Exception\NegativeAmount;
use App\Domain\Shared\Exception\NotFound;
use App\Domain\Shared\Money;
use App\Domain\Stock\LotRevision;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity]
#[ORM\Table(name: '`order`')]
#[ORM\Index(name: 'order_workspace_placed_at_idx', columns: ['workspace_id', 'placed_at'])]
#[ORM\UniqueConstraint(name: 'order_workspace_reference', columns: ['workspace_id', 'reference'])]
class Order implements Referenced
{
    public const string MANUAL = 'manual';

    private const int IMPORT_ROUNDING_TOLERANCE_CENTS = 2;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\Column(length: 64)]
    private string $reference;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Event $event;

    #[ORM\ManyToOne(targetEntity: SalesChannel::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SalesChannel $channel = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $placedAt;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, OrderSupply> */
    #[ORM\OneToMany(targetEntity: OrderSupply::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $supplies;

    /** @var list<array{label: string, amount: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $appliedDiscounts = [];

    #[ORM\Column(length: 32)]
    private string $source;

    /** @var Collection<int, ImportedSale> */
    #[ORM\OneToMany(targetEntity: ImportedSale::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $importedSales;

    #[ORM\Column(length: 16, nullable: true, enumType: PaymentMethod::class)]
    private ?PaymentMethod $paymentMethod = null;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'shipping_')]
    private Money $shipping;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    /** @var list<array{label: string, amount: int}> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $channelCharges = [];

    #[ORM\Embedded(class: Money::class, columnPrefix: 'postage_')]
    private Money $postage;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'subtotal_')]
    private Money $subtotal;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'discount_total_')]
    private Money $discountTotal;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'total_')]
    private Money $total;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'cost_of_goods_')]
    private Money $costOfGoods;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'supplies_cost_')]
    private Money $suppliesCost;

    #[ORM\Embedded(class: Money::class, columnPrefix: 'channel_costs_')]
    private Money $channelCosts;

    /**
     * @param list<OrderedItem>     $items
     * @param list<AppliedDiscount> $discounts
     */
    private function __construct(string $reference, Workspace $workspace, ?Event $event, \DateTimeImmutable $placedAt, array $items, array $discounts, string $source, ?SalesChannel $channel)
    {
        if (null !== $event && !$event->covers($placedAt)) {
            throw new OrderOutsideEvent($event->name(), $placedAt);
        }
        if (null === $event && null !== $channel && !$channel->acceptsOrderWithoutEvent()) {
            throw new MarketOrderWithoutEvent($channel->name());
        }
        if ([] === $items) {
            throw new EmptyOrder();
        }

        $this->id = new Ulid();
        $this->reference = $reference;
        $this->event = $event;
        $this->channel = $channel;
        $this->workspace = $workspace;
        $this->shipping = Money::zero();
        $this->postage = Money::zero();
        $this->channelCosts = Money::zero();
        $this->placedAt = $placedAt;
        $this->source = $source;
        $this->lines = new ArrayCollection();
        $this->supplies = new ArrayCollection();
        $this->importedSales = new ArrayCollection();

        foreach ($items as $item) {
            $this->addItem($item);
        }

        $this->applyDiscounts($discounts);
        $this->chargeChannelCosts();
    }

    /**
     * @param list<OrderedItem>     $items
     * @param list<AppliedDiscount> $discounts
     */
    public static function place(string $reference, Event $event, \DateTimeImmutable $placedAt, array $items, array $discounts, ?SalesChannel $channel = null): self
    {
        return new self($reference, $event->workspace(), $event, $placedAt, $items, $discounts, self::MANUAL, $channel);
    }

    /**
     * @param list<OrderedItem>     $items
     * @param list<AppliedDiscount> $ruleDiscounts
     */
    public static function imported(string $reference, Workspace $workspace, string $source, string $externalId, string $saleReference, ?Event $event, \DateTimeImmutable $placedAt, array $items, Money $charged, Money $shipping, ?PaymentMethod $paymentMethod, array $ruleDiscounts, string $discountLabel, ?SalesChannel $channel = null): self
    {
        if ($shipping->isNegative()) {
            throw new NegativeShippingCost();
        }

        $order = new self($reference, $workspace, $event, $placedAt, $items, [], $source, $channel);
        $order->importedSales->add(new ImportedSale($order, $source, $externalId, $saleReference, $paymentMethod));
        $order->paymentMethod = $paymentMethod;
        $order->shipping = $shipping;

        $gap = $order->linesTotal()->subtract($charged);
        if ($gap->isPositive()) {
            $order->applyDiscounts(self::importDiscounts($gap, $ruleDiscounts, $discountLabel));
        }
        $order->chargeChannelCosts();

        return $order;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function discountTotal(): Money
    {
        return $this->discountTotal;
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function shipping(): Money
    {
        return $this->shipping;
    }

    public function costOfGoods(): Money
    {
        return $this->costOfGoods;
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
        return ReferenceSubject::at($this->placedAt);
    }

    public function changeReference(string $reference): void
    {
        $this->reference = $reference;
    }

    public function channel(): ?SalesChannel
    {
        return $this->channel;
    }

    public function event(): ?Event
    {
        return $this->event;
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return list<ImportedSale>
     */
    public function importedSales(): array
    {
        return array_values($this->importedSales->toArray());
    }

    public function settleSaleFee(string $source, string $externalId, Money $fee): void
    {
        if ($fee->isNegative()) {
            throw new NegativeAmount('payment_fee');
        }

        foreach ($this->importedSales as $sale) {
            if ($sale->isFrom($source, $externalId)) {
                $sale->settleFee($fee);
            }
        }
    }

    public function saleFees(): ?Money
    {
        if ($this->importedSales->isEmpty()) {
            return null;
        }

        $total = Money::zero();
        foreach ($this->importedSales as $sale) {
            $fee = $sale->fee();
            if (null === $fee) {
                return null;
            }
            $total = $total->add($fee);
        }

        return $total;
    }

    public function refund(\DateTimeImmutable $refundedAt): void
    {
        if ($this->isRefunded()) {
            throw new OrderAlreadyRefunded($this->reference);
        }

        $this->refundedAt = $refundedAt;
    }

    public function isRefunded(): bool
    {
        return null !== $this->refundedAt;
    }

    public function refundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function check(\DateTimeImmutable $checkedAt): void
    {
        $this->checkedAt ??= $checkedAt;
    }

    public function uncheck(): void
    {
        $this->checkedAt = null;
    }

    public function checkedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function canAbsorb(self $other): bool
    {
        return null === $this->mergeObstacleWith($other);
    }

    public function absorb(self $other): void
    {
        $obstacle = $this->mergeObstacleWith($other);
        if (null !== $obstacle) {
            throw new OrdersNotMergeable($obstacle);
        }

        foreach ($other->lines() as $line) {
            $twin = $this->lineTwinOf($line);
            if (null === $twin) {
                $this->lines->add($line->copyInto($this));
                continue;
            }
            $twin->add($line->quantity(), $line->cost());
        }
        foreach ($other->supplies() as $supply) {
            $twin = $this->supplyUsing($supply->productId(), $supply->variant());
            if (null === $twin) {
                $this->supplies->add($supply->copyInto($this));
                continue;
            }
            $twin->add($supply->quantity(), $supply->cost());
        }
        foreach ($other->importedSales() as $sale) {
            $sale->joinOrder($this);
            $this->importedSales->add($sale);
        }
        $other->importedSales->clear();

        $this->appliedDiscounts = [...$this->appliedDiscounts, ...$other->appliedDiscounts];
        $this->shipping = $this->shipping->add($other->shipping);
        $this->placedAt = min($this->placedAt, $other->placedAt);
        $this->paymentMethod = PaymentMethod::combined($this->paymentMethod, $other->paymentMethod);
        $this->postage = $this->postage->add($other->postage);
        $this->chargeChannelCosts();
    }

    /**
     * @param list<OrderCharge> $paymentFees
     */
    public function chargeChannelCosts(array $paymentFees = []): void
    {
        $this->recordSales();
        $charges = [...(null === $this->channel ? [] : $this->channel->chargesOn($this->total)), ...$paymentFees];
        $this->channelCharges = array_map(static fn (OrderCharge $charge): array => $charge->toArray(), $charges);
        $this->recordChannelCosts();
    }

    /**
     * @return list<OrderCharge>
     */
    public function channelCharges(): array
    {
        return array_map(OrderCharge::fromArray(...), $this->channelCharges);
    }

    public function stamp(Money $postage): void
    {
        if ($postage->isNegative()) {
            throw new NegativePostage();
        }

        $this->postage = $postage;
        $this->recordChannelCosts();
    }

    public function postage(): Money
    {
        return $this->postage;
    }

    public function channelCosts(): Money
    {
        return $this->channelCosts;
    }

    public function profit(): Money
    {
        return $this->total->subtract($this->costOfGoods)->subtract($this->suppliesCost)->subtract($this->channelCosts);
    }

    private function lineTwinOf(OrderLine $line): ?OrderLine
    {
        return $this->lines->findFirst(static fn (int $key, OrderLine $candidate): bool => $candidate->sellsSameAs($line) && $candidate->sameUnitAmountsAs($line));
    }

    public function fillMissingCosts(Ulid $productId, ?string $variant, Money $unitCost): int
    {
        if ($unitCost->isZero()) {
            return 0;
        }
        $filled = 0;
        foreach ($this->lines as $line) {
            if ($line->hasUnknownCost() && $line->isFor($productId, $variant)) {
                $line->costAt($unitCost);
                ++$filled;
            }
        }
        $this->recordSales();

        return $filled;
    }

    public function followLot(Ulid $productId, ?string $variant, LotRevision $revision): int
    {
        if ($this->isRefunded()) {
            return 0;
        }
        $followed = 0;
        foreach ($this->lines() as $line) {
            $followed += (int) ($line->isFor($productId, $variant) && $line->followLot($revision));
        }
        foreach ($this->supplies() as $supply) {
            $followed += (int) ($supply->uses($productId, $variant) && $supply->followLot($revision));
        }
        $this->recordSales();

        return $followed;
    }

    public function unknownCostLines(): int
    {
        return \count(array_filter($this->lines(), static fn (OrderLine $line): bool => $line->hasUnknownCost()));
    }

    public function unidentifiedLines(): int
    {
        return \count(array_filter($this->lines(), static fn (OrderLine $line): bool => $line->sellsUnknownProduct()));
    }

    public function useSupply(SellableItem $supply, int $quantity, Money $cost): void
    {
        if ($this->isRefunded()) {
            throw new RefundedOrderLocked($this->reference);
        }
        $productId = $supply->productId;
        if (null === $productId || null === $this->channel || !$this->channel->offers($productId)) {
            throw new SupplyNotOnChannel($supply->label(), $this->reference);
        }

        $twin = $this->supplyUsing($productId, $supply->variant);
        if (null === $twin) {
            $this->supplies->add(new OrderSupply($this, $productId, $supply, $quantity, $cost));
        } else {
            $twin->add($quantity, $cost);
        }
        $this->recordSales();
    }

    public function returnSupply(Ulid $supplyLineId): OrderSupply
    {
        if ($this->isRefunded()) {
            throw new RefundedOrderLocked($this->reference);
        }
        $supply = $this->supplies->findFirst(static fn (int $key, OrderSupply $supply): bool => $supply->id()->equals($supplyLineId))
            ?? throw new NotFound('order_supply', (string) $supplyLineId);
        $this->supplies->removeElement($supply);
        $this->recordSales();

        return $supply;
    }

    /**
     * @return list<OrderSupply>
     */
    public function supplies(): array
    {
        return array_values($this->supplies->toArray());
    }

    public function suppliesCost(): Money
    {
        return $this->suppliesCost;
    }

    private function supplyUsing(Ulid $productId, ?string $variant): ?OrderSupply
    {
        return $this->supplies->findFirst(static fn (int $key, OrderSupply $supply): bool => $supply->uses($productId, $variant));
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    /**
     * @return list<OrderLine>
     */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function moveSales(Ulid $productId, ?string $variant, SellableItem $to, ?Money $unitCost = null, ?string $raisedPricesDiscount = null): void
    {
        $raised = Money::zero();
        foreach ($this->lines() as $line) {
            if (!$line->isFor($productId, $variant)) {
                continue;
            }
            if (null !== $raisedPricesDiscount) {
                $raised = $raised->add($line->raiseTo($to->sellingPrice));
            }
            if (null !== $unitCost && !$unitCost->isZero() && $line->hasUnknownCost()) {
                $line->costAt($unitCost);
            }

            $twin = $this->lineSelling($to, $line);
            if (null === $twin) {
                $line->reassign($to);
                continue;
            }
            $twin->add($line->quantity(), $line->cost());
            $this->lines->removeElement($line);
        }
        if (null !== $raisedPricesDiscount && $raised->isPositive()) {
            $this->appliedDiscounts = self::withDiscount($this->appliedDiscounts(), $raisedPricesDiscount, $raised);
        }
        $this->recordSales();
    }

    /**
     * @param list<AppliedDiscount> $discounts
     *
     * @return list<array{label: string, amount: int, ruleId: ?string, manual?: array{kind: string, value: int}}>
     */
    private static function withDiscount(array $discounts, string $label, Money $amount): array
    {
        $added = false;
        foreach ($discounts as $index => $discount) {
            if (!$added && null === $discount->ruleId && $label === $discount->label) {
                $discounts[$index] = new AppliedDiscount($label, $discount->amount->add($amount));
                $added = true;
            }
        }

        return array_map(static fn (AppliedDiscount $discount): array => $discount->toArray(), $added ? $discounts : [...$discounts, new AppliedDiscount($label, $amount)]);
    }

    private function line(Ulid $lineId): OrderLine
    {
        return $this->lines->findFirst(static fn (int $key, OrderLine $line): bool => $line->id()->equals($lineId))
            ?? throw new NotFound('order_line', (string) $lineId);
    }

    public function lineToIdentify(Ulid $lineId): OrderLine
    {
        if ($this->isRefunded()) {
            throw new RefundedOrderLocked($this->reference);
        }

        return $this->line($lineId);
    }

    public function identifyLine(Ulid $lineId, SellableItem $item, Money $cost): void
    {
        $this->lineToIdentify($lineId)->identify($item, $cost);
        $this->recordSales();
    }

    private function lineSelling(SellableItem $item, OrderLine $except): ?OrderLine
    {
        return $this->lines->findFirst(static fn (int $key, OrderLine $line): bool => $line !== $except && $line->sells($item) && $line->sameUnitAmountsAs($except));
    }

    public function itemCount(): int
    {
        return array_sum($this->lines->map(static fn (OrderLine $line): int => $line->quantity())->toArray());
    }

    /**
     * @return list<AppliedDiscount>
     */
    public function appliedDiscounts(): array
    {
        return array_map(AppliedDiscount::fromArray(...), $this->appliedDiscounts);
    }

    public function grantDiscount(DiscountRule $rule): void
    {
        if ($this->isRefunded()) {
            throw new RefundedOrderLocked($this->reference);
        }
        $granted = $rule->grantOn($this->placedAt);
        $discounts = $this->appliedDiscounts();
        if (array_any($discounts, static fn (AppliedDiscount $discount): bool => $discount->isManual() && $rule->id()->equals($discount->ruleId))) {
            throw new DiscountAlreadyGranted($rule->name(), $this->reference);
        }

        $this->settleDiscounts([...$discounts, $granted]);
    }

    public function withdrawDiscount(Ulid $ruleId): void
    {
        if ($this->isRefunded()) {
            throw new RefundedOrderLocked($this->reference);
        }
        $discounts = $this->appliedDiscounts();
        $kept = array_values(array_filter($discounts, static fn (AppliedDiscount $discount): bool => !$discount->isManual() || !$ruleId->equals($discount->ruleId)));
        if (\count($kept) === \count($discounts)) {
            throw new NotFound('discount', (string) $ruleId);
        }

        $this->settleDiscounts($kept);
    }

    /**
     * @param list<AppliedDiscount> $discounts
     */
    private function settleDiscounts(array $discounts): void
    {
        $this->applyDiscounts(AppliedDiscount::settle($discounts, $this->linesTotal()));
        $this->recordSales();
    }

    private function addItem(OrderedItem $ordered): void
    {
        $line = $this->lines->findFirst(static fn (int $key, OrderLine $line): bool => $line->sells($ordered->item));
        if (null === $line) {
            $this->lines->add(new OrderLine($this, $ordered->item, $ordered->quantity, $ordered->cost()));

            return;
        }

        $line->add($ordered->quantity, $ordered->cost());
    }

    /**
     * @param list<AppliedDiscount> $discounts
     */
    private function applyDiscounts(array $discounts): void
    {
        if (AppliedDiscount::total($discounts)->greaterThan($this->linesTotal())) {
            throw new DiscountExceedsSubtotal();
        }

        $this->appliedDiscounts = array_map(static fn (AppliedDiscount $discount): array => $discount->toArray(), $discounts);
    }

    /**
     * @param list<AppliedDiscount> $ruleDiscounts
     *
     * @return list<AppliedDiscount>
     */
    private static function importDiscounts(Money $gap, array $ruleDiscounts, string $label): array
    {
        $rounding = abs($gap->subtract(AppliedDiscount::total($ruleDiscounts))->amount());

        return [] !== $ruleDiscounts && $rounding <= self::IMPORT_ROUNDING_TOLERANCE_CENTS
            ? $ruleDiscounts
            : [new AppliedDiscount($label, $gap)];
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    private function linesTotal(): Money
    {
        return Money::sum($this->lines->map(static fn (OrderLine $line): Money => $line->total()));
    }

    private function recordSales(): void
    {
        $lines = $this->lines();
        $this->subtotal = $this->linesTotal();
        $this->discountTotal = AppliedDiscount::total($this->appliedDiscounts());
        $this->total = $this->subtotal->subtract($this->discountTotal)->add($this->shipping);
        $shares = CostAllocation::proportionally($this->discountTotal, array_map(static fn (OrderLine $line): Money => $line->total(), $lines));
        foreach ($lines as $index => $line) {
            $line->shareDiscount($shares[$index]);
        }
        $this->costOfGoods = Money::sum(array_map(static fn (OrderLine $line): Money => $line->cost(), $lines));
        $this->suppliesCost = Money::sum(array_map(static fn (OrderSupply $supply): Money => $supply->cost(), $this->supplies()));
    }

    private function recordChannelCosts(): void
    {
        $this->channelCosts = Money::sum(array_map(static fn (OrderCharge $charge): Money => $charge->amount, $this->channelCharges()))->add($this->postage);
    }

    private function mergeObstacleWith(self $other): ?string
    {
        return match (true) {
            $other === $this => 'same_order',
            $this->isRefunded() || $other->isRefunded() => 'refunded',
            $other->event !== $this->event => 'different_event',
            $other->source !== $this->source => 'different_source',
            default => null,
        };
    }
}
