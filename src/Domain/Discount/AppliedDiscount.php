<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final readonly class AppliedDiscount
{
    /**
     * @param list<CoveredUnits> $covers
     */
    public function __construct(
        public string $label,
        public Money $amount,
        public ?Ulid $ruleId = null,
        public ?DiscountAction $manualAction = null,
        public array $covers = [],
    ) {
    }

    public static function granted(string $label, Ulid $ruleId, DiscountAction $action): self
    {
        return new self($label, Money::zero(), $ruleId, $action);
    }

    public function isManual(): bool
    {
        return null !== $this->manualAction;
    }

    public function awaitsItsCovers(): bool
    {
        return null !== $this->ruleId && !$this->isManual() && [] === $this->covers;
    }

    /**
     * @param list<self> $calculated
     */
    public function coveredAs(array $calculated): self
    {
        foreach ($calculated as $discount) {
            if ($this->awaitsItsCovers() && null !== $discount->ruleId && $discount->ruleId->equals($this->ruleId)) {
                return new self($this->label, $this->amount, $this->ruleId, null, $discount->covers);
            }
        }

        return $this;
    }

    public function withSalesMoved(Ulid $productId, ?string $variant, Ulid $toProductId, ?string $toVariant): self
    {
        $covers = array_map(static fn (CoveredUnits $cover): CoveredUnits => $cover->covers($productId, $variant) ? $cover->movedTo($toProductId, $toVariant) : $cover, $this->covers);

        return new self($this->label, $this->amount, $this->ruleId, $this->manualAction, $covers);
    }

    /**
     * @param list<self> $discounts
     */
    public static function total(array $discounts): Money
    {
        return Money::sum(array_map(static fn (self $discount): Money => $discount->amount, $discounts));
    }

    /**
     * @param list<self> $discounts
     *
     * @return list<self>
     */
    public static function settle(array $discounts, Money $subtotal): array
    {
        $remaining = $subtotal->subtract(self::total(array_values(array_filter($discounts, static fn (self $discount): bool => !$discount->isManual()))));

        $settled = [];
        foreach ($discounts as $discount) {
            if (null === $discount->manualAction) {
                $settled[] = $discount;
                continue;
            }
            $saving = $discount->manualAction->saving($remaining);
            $amount = $saving->isPositive() ? $saving : Money::zero();
            $remaining = $remaining->subtract($amount);
            $settled[] = new self($discount->label, $amount, $discount->ruleId, $discount->manualAction);
        }

        return $settled;
    }

    /**
     * @return array{label: string, amount: int, ruleId: ?string, manual?: array{kind: string, value: int}}
     */
    public function toArray(): array
    {
        $data = ['label' => $this->label, 'amount' => $this->amount->amount(), 'ruleId' => null === $this->ruleId ? null : (string) $this->ruleId];

        return null === $this->manualAction ? $data : [...$data, 'manual' => ['kind' => $this->manualAction->kind()->value, 'value' => $this->manualAction->value()]];
    }

    /**
     * @return array{label: string, amount: int, ruleId: ?string, manual?: array{kind: string, value: int}, covers?: list<array{productId: string, variant: ?string, quantity: int}>}
     */
    public function stored(): array
    {
        return [] === $this->covers ? $this->toArray() : [...$this->toArray(), 'covers' => array_map(static fn (CoveredUnits $cover): array => $cover->toArray(), $this->covers)];
    }

    /**
     * @param array{label: string, amount: int, ruleId?: ?string, manual?: array{kind: string, value: int}, covers?: list<array{productId: string, variant: ?string, quantity: int}>} $data
     */
    public static function fromArray(array $data): self
    {
        $ruleId = $data['ruleId'] ?? null;
        $manual = $data['manual'] ?? null;

        return new self(
            $data['label'],
            Money::cents($data['amount']),
            null === $ruleId ? null : Ulid::fromString($ruleId),
            null === $manual ? null : DiscountAction::of(DiscountActionKind::from($manual['kind']), $manual['value']),
            array_map(CoveredUnits::fromArray(...), $data['covers'] ?? []),
        );
    }
}
