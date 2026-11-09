<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use Symfony\Component\Uid\Ulid;

final readonly class TargetEvent
{
    public function __construct(
        private EventRepository $events,
    ) {
    }

    public function of(?string $eventId): ?Event
    {
        return null === $eventId ? null : $this->events->get(Ulid::fromString($eventId));
    }
}
