<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Domain\Accounting\DeclarationPeriod;
use App\Domain\Reporting\SalesTotals;
use App\Domain\Shared\Money;

final readonly class PeriodTurnover
{
    public const string NO_PAYMENT_METHOD = 'none';

    /**
     * @param array<string, Money> $byPayment
     */
    public function __construct(
        public DeclarationPeriod $period,
        public Money $turnover,
        public int $orderCount,
        public array $byPayment = [],
    ) {
    }

    /**
     * @param array<string, SalesTotals>          $salesByMonth
     * @param array<string, array<string, Money>> $paymentsByMonth
     */
    public static function of(DeclarationPeriod $period, array $salesByMonth, array $paymentsByMonth = []): self
    {
        $sales = SalesTotals::zero();
        $byPayment = [];
        foreach ($period->months() as $month) {
            $sales = $sales->add($salesByMonth[$month] ?? SalesTotals::zero());
            foreach ($paymentsByMonth[$month] ?? [] as $method => $turnover) {
                $byPayment[$method] = ($byPayment[$method] ?? Money::zero())->add($turnover);
            }
        }

        return new self($period, $sales->turnover(), $sales->orderCount, $byPayment);
    }
}
