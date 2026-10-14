<?php

declare(strict_types=1);

namespace App\Domain\Discount;

use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final class DiscountCalculator
{
    /**
     * @param list<BasketLine>   $basket
     * @param list<DiscountRule> $rules
     *
     * @return list<AppliedDiscount>
     */
    public function calculate(array $basket, array $rules, \DateTimeImmutable $placedAt): array
    {
        $rules = array_values(array_filter($rules, static fn (DiscountRule $rule): bool => $rule->appliesAutomaticallyOn($placedAt)));
        usort($rules, static fn (DiscountRule $a, DiscountRule $b): int => strcmp($a->name(), $b->name()));

        $units = $this->expand($basket);
        /** @var array<string, array{rule: DiscountRule, times: int, saving: Money}> $applied */
        $applied = [];

        while (null !== $best = $this->bestApplication($units, $rules)) {
            [$rule, $unitKeys, $saving] = $best;
            foreach ($unitKeys as $key) {
                unset($units[$key]);
            }

            $id = (string) $rule->id();
            $applied[$id] ??= ['rule' => $rule, 'times' => 0, 'saving' => Money::zero()];
            ++$applied[$id]['times'];
            $applied[$id]['saving'] = $applied[$id]['saving']->add($saving);
        }

        return array_values(array_map(
            static fn (array $entry): AppliedDiscount => new AppliedDiscount(
                $entry['times'] > 1 ? \sprintf('%s ×%d', $entry['rule']->name(), $entry['times']) : $entry['rule']->name(),
                $entry['saving'],
                $entry['rule']->id(),
            ),
            $applied,
        ));
    }

    /**
     * @param list<BasketLine> $basket
     *
     * @return array<int, array{productId: Ulid, typeId: Ulid, variant: ?string, collectionId: ?Ulid, price: Money}>
     */
    private function expand(array $basket): array
    {
        $units = [];
        foreach ($basket as $line) {
            for ($i = 0; $i < $line->quantity; ++$i) {
                $units[] = ['productId' => $line->productId, 'typeId' => $line->typeId, 'variant' => $line->variant, 'collectionId' => $line->collectionId, 'price' => $line->unitPrice];
            }
        }

        usort($units, static fn (array $a, array $b): int => $b['price']->amount() <=> $a['price']->amount());

        return $units;
    }

    /**
     * @param array<int, array{productId: Ulid, typeId: Ulid, variant: ?string, collectionId: ?Ulid, price: Money}> $units
     * @param list<DiscountRule>                                                                                    $rules
     *
     * @return array{DiscountRule, list<int>, Money}|null
     */
    private function bestApplication(array $units, array $rules): ?array
    {
        $best = null;

        foreach ($rules as $rule) {
            $taken = $this->take($units, $rule);
            if (null === $taken) {
                continue;
            }

            $saving = $rule->action()->saving(Money::sum(array_map(static fn (int $key): Money => $units[$key]['price'], $taken)));
            if ($saving->isPositive() && (null === $best || $saving->greaterThan($best[2]))) {
                $best = [$rule, $taken, $saving];
            }
        }

        return $best;
    }

    /**
     * @param array<int, array{productId: Ulid, typeId: Ulid, variant: ?string, collectionId: ?Ulid, price: Money}> $units
     *
     * @return list<int>|null
     */
    private function take(array $units, DiscountRule $rule): ?array
    {
        $taken = [];
        foreach ($rule->conditionsMostSpecificFirst() as $condition) {
            $matching = array_keys(array_filter($units, static fn (array $unit): bool => $condition->matches($unit['productId'], $unit['typeId'], $unit['variant'], $unit['collectionId'])));
            if (\count($matching) < $condition->quantity()) {
                return null;
            }
            foreach (\array_slice($matching, 0, $condition->quantity()) as $key) {
                $taken[] = $key;
                unset($units[$key]);
            }
        }

        return $taken;
    }
}
