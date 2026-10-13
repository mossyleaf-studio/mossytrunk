<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Event\Event;
use App\Domain\Event\ExpenseShares;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\Money;

final readonly class MonthlyResults
{
    /**
     * @param array<string, SalesFigures> $months
     */
    private function __construct(private array $months)
    {
    }

    /**
     * @param array<string, SalesTotals> $salesByMonth
     * @param list<Event>                $events
     * @param array<string, Money>       $consumedSuppliesByEvent
     */
    public static function of(array $salesByMonth, array $events, ExpenseShares $shares, array $consumedSuppliesByEvent = []): self
    {
        $expensesByMonth = [];
        $consumedByMonth = [];
        $donationsByMonth = [];
        foreach ($events as $event) {
            $month = BusinessTime::month($event->period()->start());
            $expensesByMonth[$month] = ($expensesByMonth[$month] ?? Money::zero())->add($shares->totalOf($event));
            $consumedByMonth[$month] = ($consumedByMonth[$month] ?? Money::zero())->add($consumedSuppliesByEvent[(string) $event->id()] ?? Money::zero());
            $donationsByMonth[$month] = ($donationsByMonth[$month] ?? Money::zero())->add($event->donationsTotal());
        }

        $months = [];
        foreach (array_unique([...array_keys($salesByMonth), ...array_keys($expensesByMonth)]) as $month) {
            $months[$month] = SalesFigures::of($salesByMonth[$month] ?? SalesTotals::zero(), $expensesByMonth[$month] ?? Money::zero(), $consumedByMonth[$month] ?? Money::zero(), $donationsByMonth[$month] ?? Money::zero());
        }

        return new self($months);
    }

    public function month(int $year, int $month): SalesFigures
    {
        return $this->months[\sprintf('%04d-%02d', $year, $month)] ?? SalesFigures::zero();
    }

    public function year(int $year): SalesFigures
    {
        $total = SalesFigures::zero();
        for ($month = 1; $month <= 12; ++$month) {
            $total = $total->add($this->month($year, $month));
        }

        return $total;
    }

    /**
     * @return list<int>
     */
    public function years(int ...$included): array
    {
        return SalesYears::of(array_keys($this->months), ...$included);
    }
}
