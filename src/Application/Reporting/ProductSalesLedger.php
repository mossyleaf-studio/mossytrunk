<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Reporting\ProductSales;
use App\Domain\Shared\DateRange;
use Symfony\Component\Uid\Ulid;

interface ProductSalesLedger
{
    /**
     * @return list<ProductSales>
     */
    public function ofEvent(Ulid $eventId): array;

    /**
     * @return list<ProductSales>
     */
    public function within(DateRange $period): array;

    public function ofProduct(Ulid $productId, ?DateRange $period = null): ?ProductSales;
}
