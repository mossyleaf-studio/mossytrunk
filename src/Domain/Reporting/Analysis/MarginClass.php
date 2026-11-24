<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

use App\Domain\Shared\Money;

enum MarginClass: string
{
    public const int A_UNDER_PERCENT = 80;
    public const int B_UNDER_PERCENT = 95;

    case A = 'A';
    case B = 'B';
    case C = 'C';

    /**
     * @param array<string, array{name: string, margin: Money}> $products
     *
     * @return array<string, array{class: self, cumulative: Money}>
     */
    public static function ranking(array $products): array
    {
        uasort($products, static fn (array $a, array $b): int => [$b['margin']->amount(), $a['name']] <=> [$a['margin']->amount(), $b['name']]);
        $total = Money::sum(array_filter(array_column($products, 'margin'), static fn (Money $margin): bool => $margin->isPositive()));
        $cumulative = Money::zero();
        $ranking = [];
        foreach ($products as $key => $product) {
            $ranking[$key] = ['class' => self::at($cumulative, $product['margin'], $total), 'cumulative' => $cumulative = $product['margin']->isPositive() ? $cumulative->add($product['margin']) : $cumulative];
        }

        return $ranking;
    }

    public static function at(Money $before, Money $margin, Money $total): self
    {
        return match (true) {
            !$margin->isPositive() => self::C,
            100 * $before->amount() < self::A_UNDER_PERCENT * $total->amount() => self::A,
            100 * $before->amount() < self::B_UNDER_PERCENT * $total->amount() => self::B,
            default => self::C,
        };
    }
}
