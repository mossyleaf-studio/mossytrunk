<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Discount\AppliedDiscount;
use App\Domain\Discount\CoveredUnits;
use App\Domain\Shared\CostAllocation;
use App\Domain\Shared\Money;

final class DiscountShares
{
    /**
     * @param list<AppliedDiscount> $discounts
     * @param list<OrderLine>       $lines
     *
     * @return list<Money>
     */
    public static function of(array $discounts, array $lines): array
    {
        $totals = array_map(static fn (OrderLine $line): Money => $line->total(), $lines);
        $shares = array_map(static fn (): Money => Money::zero(), $lines);
        $sharedByAll = Money::zero();
        foreach ($discounts as $discount) {
            $covered = self::coveredAmounts($discount->covers, $lines);
            if (!Money::sum($covered)->isPositive()) {
                $sharedByAll = $sharedByAll->add($discount->amount);
                continue;
            }
            $shares = self::added($shares, CostAllocation::proportionally($discount->amount, $covered));
        }

        return self::added($shares, CostAllocation::proportionally($sharedByAll, $totals));
    }

    /**
     * @param list<CoveredUnits> $covers
     * @param list<OrderLine>    $lines
     *
     * @return list<Money>
     */
    private static function coveredAmounts(array $covers, array $lines): array
    {
        $left = array_map(static fn (CoveredUnits $cover): int => $cover->quantity, $covers);
        $amounts = [];
        foreach ($lines as $line) {
            $units = 0;
            foreach ($covers as $index => $cover) {
                $taken = $cover->covers($line->productId(), $line->variant()) ? min($left[$index], $line->quantity() - $units) : 0;
                $units += $taken;
                $left[$index] -= $taken;
            }
            $amounts[] = $line->unitPrice()->multiply($units);
        }

        return $amounts;
    }

    /**
     * @param list<Money> $shares
     * @param list<Money> $more
     *
     * @return list<Money>
     */
    private static function added(array $shares, array $more): array
    {
        return array_map(static fn (Money $share, Money $extra): Money => $share->add($extra), $shares, $more);
    }
}
