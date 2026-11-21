<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\Exception\DateRangeEndsBeforeStart;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class DateRange
{
    private function __construct(
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $start,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $end,
    ) {
    }

    public static function fromDates(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        $start = BusinessTime::midnightOf($start);
        $end = BusinessTime::midnightOf($end);

        if ($end < $start) {
            throw new DateRangeEndsBeforeStart();
        }

        return new self($start, $end);
    }

    public static function year(int $year): self
    {
        return new self(BusinessTime::at(\sprintf('%04d-01-01', $year)), BusinessTime::at(\sprintf('%04d-12-31', $year)));
    }

    public function start(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): \DateTimeImmutable
    {
        return $this->end;
    }

    public function days(): int
    {
        return $this->start->diff($this->end)->days + 1;
    }

    public function daysLeftOn(\DateTimeImmutable $moment): int
    {
        $from = max(BusinessTime::day($moment), $this->start->format('Y-m-d'));
        $end = $this->end->format('Y-m-d');
        $utc = new \DateTimeZone('UTC');

        return $from > $end ? 0 : (int) new \DateTimeImmutable($from, $utc)->diff(new \DateTimeImmutable($end, $utc))->days + 1;
    }

    public static function yearOf(\DateTimeImmutable $moment): int
    {
        return (int) BusinessTime::local($moment)->format('Y');
    }

    public function covers(\DateTimeImmutable $moment): bool
    {
        $day = BusinessTime::day($moment);

        return $day >= $this->start->format('Y-m-d') && $day <= $this->end->format('Y-m-d');
    }

    public function isAfter(\DateTimeImmutable $moment): bool
    {
        return $this->start->format('Y-m-d') > BusinessTime::day($moment);
    }

    public function isBefore(\DateTimeImmutable $moment): bool
    {
        return $this->end->format('Y-m-d') < BusinessTime::day($moment);
    }
}
