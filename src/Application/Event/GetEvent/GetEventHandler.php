<?php

declare(strict_types=1);

namespace App\Application\Event\GetEvent;

use App\Application\Planning\PlanningCalendar;
use App\Domain\Event\EventRepository;
use App\Domain\Event\ExpenseShares;
use Symfony\Component\Uid\Ulid;

final readonly class GetEventHandler
{
    public function __construct(
        private EventRepository $events,
        private PlanningCalendar $calendar,
    ) {
    }

    public function __invoke(string $eventId): EventView
    {
        $event = $this->events->get(Ulid::fromString($eventId));
        $day = $this->calendar->today();

        return EventView::fromEvent($event, $event->timingOn($day->today), ExpenseShares::among($this->events->all()), $day->readyByOf($event));
    }
}
