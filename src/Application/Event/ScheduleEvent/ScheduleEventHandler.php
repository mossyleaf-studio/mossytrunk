<?php

declare(strict_types=1);

namespace App\Application\Event\ScheduleEvent;

use App\Application\Transaction;
use App\Application\WorkspaceContext;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventScheduler;
use App\Domain\Shared\DateRange;
use Symfony\Component\Uid\Ulid;

final readonly class ScheduleEventHandler
{
    public function __construct(
        private EventRepository $events,
        private EventScheduler $scheduler,
        private Transaction $transaction,
        private WorkspaceContext $workspace,
    ) {
    }

    public function __invoke(ScheduleEvent $command): Ulid
    {
        $period = DateRange::fromDates($command->startDate, $command->endDate);
        $this->scheduler->ensureFree($period);

        $event = Event::schedule($this->workspace->current(), $command->name, $command->location, $period);
        $event->planReadiness($command->readyDaysBefore);
        $this->events->add($event);
        $this->transaction->commit();

        return $event->id();
    }
}
