<?php

declare(strict_types=1);

namespace App\Application\Event\ListEvents;

use App\Application\Reporting\SalesLedger;
use App\Application\Stock\ConsumedSupplies;
use App\Domain\Event\EventRepository;
use App\Domain\Event\ExpenseShares;
use App\Domain\Reporting\SalesFigures;
use App\Domain\Reporting\SalesTotals;
use App\Domain\Stock\StockCheckRepository;
use Psr\Clock\ClockInterface;

final readonly class ListEventsHandler
{
    public function __construct(
        private EventRepository $events,
        private SalesLedger $sales,
        private StockCheckRepository $checks,
        private ClockInterface $clock,
        private ConsumedSupplies $consumedSupplies,
    ) {
    }

    /**
     * @return list<EventSummaryView>
     */
    public function __invoke(): array
    {
        $sales = $this->sales->totalsByEvent();

        $unexplained = [];
        foreach ($this->checks->withUnexplainedUnits() as $check) {
            $key = (string) $check->event()->id();
            $unexplained[$key] = ($unexplained[$key] ?? 0) + $check->unexplainedUnits();
        }

        $consumed = $this->consumedSupplies->byEvent();
        $today = $this->clock->now();
        $events = $this->events->all();
        $shares = ExpenseShares::among($events);
        $views = [];
        foreach ($events as $event) {
            $key = (string) $event->id();
            $views[] = EventSummaryView::of($event, SalesFigures::of($sales[$key] ?? SalesTotals::zero(), $shares->totalOf($event), $consumed[$key] ?? null, $event->donationsTotal()), $event->timingOn($today), $unexplained[$key] ?? 0);
        }

        return $views;
    }
}
