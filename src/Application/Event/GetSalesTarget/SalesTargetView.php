<?php

declare(strict_types=1);

namespace App\Application\Event\GetSalesTarget;

use App\Domain\Reporting\SalesTarget;
use App\Domain\Reporting\UrssafContribution;

final readonly class SalesTargetView
{
    public function __construct(
        public int $expenses,
        public int $days,
        public float $urssafRate,
        public float $costRate,
        public ?int $usualPerDay,
        public int $basedOnEvents,
        public ?int $total,
        public ?int $perDay,
    ) {
    }

    public static function of(SalesTarget $target): self
    {
        return new self(
            $target->expenses->amount(),
            $target->days,
            UrssafContribution::RATE_BASIS_POINTS / 100,
            $target->costRateBasisPoints / 100,
            $target->usualPerDay?->amount(),
            $target->basedOnEvents,
            $target->total?->amount(),
            $target->perDay?->amount(),
        );
    }
}
