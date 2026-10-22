<?php

declare(strict_types=1);

namespace App\Application\Event\GetSalesTarget;

use App\Application\Reporting\SalesLedger;
use App\Application\Stock\ConsumedSupplies;
use App\Domain\Event\EventRepository;
use App\Domain\Event\EventTiming;
use App\Domain\Event\ExpenseShares;
use App\Domain\Reporting\SalesFigures;
use App\Domain\Reporting\SalesTarget;
use App\Domain\Reporting\SalesTotals;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

final readonly class GetSalesTargetHandler
{
    public function __construct(
        private EventRepository $events,
        private SalesLedger $sales,
        private ConsumedSupplies $consumedSupplies,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $eventId): SalesTargetView
    {
        $event = $this->events->get(Ulid::fromString($eventId));
        $events = $this->events->all();
        $shares = ExpenseShares::among($events);
        $sales = $this->sales->totalsByEvent();
        $consumed = $this->consumedSupplies->byEvent();
        $today = $this->clock->now();

        $pastEvents = [];
        foreach ($events as $past) {
            if (EventTiming::Past !== $past->timingOn($today)) {
                continue;
            }
            $key = (string) $past->id();
            $pastEvents[] = ['figures' => SalesFigures::of($sales[$key] ?? SalesTotals::zero(), $shares->totalOf($past), $consumed[$key] ?? null), 'days' => $past->period()->days()];
        }

        return SalesTargetView::of(SalesTarget::of($shares->totalOf($event), $event->period()->days(), $pastEvents));
    }
}
