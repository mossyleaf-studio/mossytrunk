<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

use App\Domain\Shared\Money;

final readonly class TypeBenchmark
{
    public const int POPULAR_PERCENT = 70;
    public const int PROFITABLE_PERCENT = 85;

    private function __construct(
        public int $judged,
        private float $paces,
        private int $units,
        private Money $margin,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0.0, 0, Money::zero());
    }

    public function with(Exposure $exposure, Money $margin): self
    {
        $pace = $exposure->per100Orders();
        if ($exposure->tooEarly() || null === $pace) {
            return $this;
        }

        return new self($this->judged + 1, $this->paces + $pace, $this->units + $exposure->units, $this->margin->add($margin));
    }

    public function averagePace(): ?float
    {
        return 0 === $this->judged ? null : round($this->paces / $this->judged, 1);
    }

    public function popularFrom(): ?float
    {
        $average = $this->averagePace();

        return null === $average ? null : round($average * self::POPULAR_PERCENT / 100, 1);
    }

    public function averageUnitMargin(): ?Money
    {
        return self::unitMargin($this->margin, $this->units);
    }

    public function unitMarginFrom(): ?Money
    {
        return $this->averageUnitMargin()?->prorate(self::PROFITABLE_PERCENT, 100);
    }

    public function quadrant(Exposure $exposure, Money $margin): ?Quadrant
    {
        $pace = $exposure->per100Orders();
        $unitMargin = self::unitMargin($margin, $exposure->units);
        $popularFrom = $this->popularFrom();
        $unitMarginFrom = $this->unitMarginFrom();
        if ($exposure->tooEarly() || null === $pace || null === $unitMargin || null === $popularFrom || null === $unitMarginFrom) {
            return null;
        }

        return Quadrant::of($pace >= $popularFrom, !$unitMargin->lessThan($unitMarginFrom));
    }

    public static function unitMargin(Money $margin, int $units): ?Money
    {
        return 0 === $units ? null : $margin->prorate(1, $units);
    }
}
