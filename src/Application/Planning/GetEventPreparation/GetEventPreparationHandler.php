<?php

declare(strict_types=1);

namespace App\Application\Planning\GetEventPreparation;

use App\Application\Planning\PlanningCalendar;
use App\Application\Planning\ReleaseView;
use App\Application\Planning\RestockView;
use App\Domain\Event\EventRepository;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use App\Domain\Planning\Restock;
use App\Domain\Planning\RestockRepository;
use Symfony\Component\Uid\Ulid;

final readonly class GetEventPreparationHandler
{
    public function __construct(
        private EventRepository $events,
        private ReleaseRepository $releases,
        private RestockRepository $restocks,
        private PlanningCalendar $calendar,
    ) {
    }

    public function __invoke(string $eventId): EventPreparationView
    {
        $event = $this->events->get(Ulid::fromString($eventId));
        $day = $this->calendar->today();

        return new EventPreparationView(
            [
                'id' => (string) $event->id(),
                'name' => $event->name(),
                'start' => $event->period()->start()->format('Y-m-d'),
                'end' => $event->period()->end()->format('Y-m-d'),
            ],
            $day->readyByOf($event),
            $day->readyDaysBeforeOf($event),
            array_map(static fn (Release $release): ReleaseView => ReleaseView::of($release, $day), $this->releases->ofEvent($event)),
            array_map(static fn (Restock $restock): RestockView => RestockView::of($restock, $day), $this->restocks->ofEvent($event)),
        );
    }
}
