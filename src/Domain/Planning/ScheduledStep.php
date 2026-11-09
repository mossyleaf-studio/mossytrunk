<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Shared\BusinessTime;

final readonly class ScheduledStep
{
    public function __construct(
        public string $label,
        public int $days,
        public bool $done,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public function stateOn(\DateTimeImmutable $today): StepState
    {
        $day = BusinessTime::day($today);

        return match (true) {
            $this->done => StepState::Done,
            BusinessTime::day($this->end) < $day => StepState::Late,
            BusinessTime::day($this->start) <= $day => StepState::Ongoing,
            default => StepState::Upcoming,
        };
    }
}
