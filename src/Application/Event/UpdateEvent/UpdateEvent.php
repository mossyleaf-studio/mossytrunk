<?php

declare(strict_types=1);

namespace App\Application\Event\UpdateEvent;

final readonly class UpdateEvent
{
    public function __construct(
        public string $eventId,
        public string $name,
        public string $location,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?int $readyDaysBefore = null,
    ) {
    }
}
