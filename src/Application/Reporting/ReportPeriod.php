<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Reporting\SalesYears;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use Psr\Clock\ClockInterface;

final readonly class ReportPeriod
{
    public const string LAST_TWELVE_MONTHS = '12m';
    public const string SINCE_THE_START = 'all';

    public function __construct(
        private SalesLedger $sales,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(string $choice): DateRange
    {
        $today = BusinessTime::local($this->clock->now());

        return match (true) {
            1 === preg_match('/^\d{4}$/', $choice) => DateRange::year((int) $choice),
            self::SINCE_THE_START === $choice => DateRange::fromDates(min($this->sales->firstSaleAt() ?? $today, $today), max($this->sales->lastSaleAt() ?? $today, $today)),
            default => DateRange::fromDates($today->modify('first day of this month')->modify('-11 months'), $today),
        };
    }

    /**
     * @return list<int>
     */
    public function years(): array
    {
        return SalesYears::of(array_keys($this->sales->totalsByMonth()), DateRange::yearOf($this->clock->now()));
    }
}
