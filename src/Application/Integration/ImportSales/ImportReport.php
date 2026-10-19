<?php

declare(strict_types=1);

namespace App\Application\Integration\ImportSales;

final readonly class ImportReport
{
    /**
     * @param list<string> $datesWithoutEvent
     */
    public function __construct(
        public string $service,
        public string $label,
        public int $ordersImported,
        public int $ordersAlreadyImported,
        public int $productsCreated,
        public int $typesCreated,
        public int $ordersWithoutEvent,
        public array $datesWithoutEvent,
        public int $ordersWaitingForItems,
        public int $itemsToLink,
        public int $salesWithoutItems,
        public int $feesUpdated = 0,
        public int $ordersCancelled = 0,
    ) {
    }
}
