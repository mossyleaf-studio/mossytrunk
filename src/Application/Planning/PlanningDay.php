<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Domain\Event\Event;
use App\Domain\Shared\BusinessTime;

final readonly class PlanningDay
{
    public function __construct(
        public \DateTimeImmutable $today,
        public int $readyDaysBefore,
    ) {
    }

    public function readyDaysBeforeOf(Event $event): int
    {
        return $event->readyDaysBefore() ?? $this->readyDaysBefore;
    }

    public function readyByOf(Event $event): string
    {
        return BusinessTime::day($event->readyBy($this->readyDaysBefore));
    }
}
