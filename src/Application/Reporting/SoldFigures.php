<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Reporting\ProductSales;
use App\Domain\Shared\Money;

final class SoldFigures
{
    /**
     * @return array{units: int, fullPriceUnits: int, discountedUnits: int, gross: int, discountedGross: int, discount: int, revenue: int, cost: int, margin: int, unknownCost: bool}
     */
    public static function of(?ProductSales $sold): array
    {
        $sold ??= new ProductSales('', null, '', null, 0, Money::zero(), Money::zero(), Money::zero(), false, 0, Money::zero());

        return [
            'units' => $sold->quantity,
            'fullPriceUnits' => $sold->fullPriceUnits(),
            'discountedUnits' => $sold->discountedUnits,
            'gross' => $sold->gross->amount(),
            'discountedGross' => $sold->discountedGross->amount(),
            'discount' => $sold->discount()->amount(),
            'revenue' => $sold->sales->amount(),
            'cost' => $sold->cost->amount(),
            'margin' => $sold->margin()->amount(),
            'unknownCost' => $sold->unknownCost,
        ];
    }
}
