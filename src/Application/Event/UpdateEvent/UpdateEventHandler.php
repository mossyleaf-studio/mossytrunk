<?php

declare(strict_types=1);

namespace App\Application\Event\UpdateEvent;

use App\Application\Transaction;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventScheduler;
use App\Domain\Event\Exception\OrdersOutsidePeriod;
use App\Domain\Order\OrderRepository;
use App\Domain\Shared\DateRange;
use Symfony\Component\Uid\Ulid;

final readonly class UpdateEventHandler
{
    public function __construct(
        private EventRepository $events,
        private EventScheduler $scheduler,
        private OrderRepository $orders,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(UpdateEvent $command): void
    {
        $event = $this->events->get(Ulid::fromString($command->eventId));
        $period = DateRange::fromDates($command->startDate, $command->endDate);

        $this->scheduler->ensureFree($period, $event->id());

        $ordersOutside = $this->orders->countOutside($event->id(), $period);
        if ($ordersOutside > 0) {
            throw new OrdersOutsidePeriod($ordersOutside);
        }

        $event->describe($command->name, $command->location);
        $event->reschedule($period);
        $event->planReadiness($command->readyDaysBefore);
        $this->transaction->commit();
    }
}
