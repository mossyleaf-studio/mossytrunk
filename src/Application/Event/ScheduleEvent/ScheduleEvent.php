<?php

declare(strict_types=1);

namespace App\Application\Event\ScheduleEvent;

final readonly class ScheduleEvent
{
    public function __construct(
        public string $name,
        public string $location,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?int $readyDaysBefore = null,
    ) {
    }
}
