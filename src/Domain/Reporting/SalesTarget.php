<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Shared\Money;

final readonly class SalesTarget
{
    private const int WHOLE = 10_000;

    private function __construct(
        public Money $expenses,
        public int $days,
        public int $costRateBasisPoints,
        public ?Money $usualPerDay,
        public int $basedOnEvents,
        public ?Money $total,
        public ?Money $perDay,
    ) {
    }

    /**
     * @param list<array{figures: SalesFigures, days: int}> $pastEvents
     */
    public static function of(Money $expenses, int $days, array $pastEvents): self
    {
        $selling = array_values(array_filter($pastEvents, static fn (array $past): bool => $past['figures']->turnover->isPositive()));
        $costRate = (int) round(self::median(array_map(self::costRate(...), $selling)));
        $usualPerDay = [] === $selling ? null : Money::cents((int) round(self::median(array_map(static fn (array $past): float => $past['figures']->turnover->amount() / max(1, $past['days']), $selling))));
        $margin = self::WHOLE - UrssafContribution::RATE_BASIS_POINTS - $costRate;
        $total = $margin > 0 ? Money::cents((int) ceil($expenses->amount() * self::WHOLE / $margin)) : null;

        return new self(
            $expenses,
            $days,
            $costRate,
            $usualPerDay,
            \count($selling),
            $total,
            null === $total ? null : Money::cents((int) ceil($total->amount() / max(1, $days))),
        );
    }

    /**
     * @param array{figures: SalesFigures, days: int} $past
     */
    private static function costRate(array $past): float
    {
        $figures = $past['figures'];
        $costs = $figures->costOfGoods->add($figures->supplies)->add($figures->consumedSupplies)->add($figures->channelCosts);

        return $costs->amount() * self::WHOLE / $figures->turnover->amount();
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }
        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}
