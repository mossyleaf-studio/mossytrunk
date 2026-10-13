<?php

declare(strict_types=1);

namespace App\Application\Event\GetEventReport;

use App\Application\Reporting\ProductSalesLedger;
use App\Application\Reporting\SalesLedger;
use App\Application\Stock\ConsumedSupplies;
use App\Domain\Event\EventRepository;
use App\Domain\Event\ExpenseShares;
use App\Domain\Product\ProductRepository;
use App\Domain\Reporting\ProductSales;
use App\Domain\Reporting\SalesFigures;
use Symfony\Component\Uid\Ulid;

final readonly class GetEventReportHandler
{
    public function __construct(
        private EventRepository $events,
        private SalesLedger $sales,
        private ProductSalesLedger $productSales,
        private ProductRepository $products,
        private ConsumedSupplies $consumedSupplies,
    ) {
    }

    public function __invoke(string $eventId): EventReportView
    {
        $event = $this->events->get(Ulid::fromString($eventId));

        $productSales = $this->productSales->ofEvent($event->id());
        $shares = ExpenseShares::among($this->events->all());

        return EventReportView::of(
            $event,
            SalesFigures::of($this->sales->totalsOfEvent($event->id()), $shares->totalOf($event), $this->consumedSupplies->atEvent($event->id()), $event->donationsTotal()),
            $shares,
            $productSales,
            $this->products->findByIds(ProductSales::productIds($productSales)),
        );
    }
}
