<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Reporting\Analysis\EventUnits;
use App\Domain\Reporting\Analysis\SellingDay;
use App\Domain\Shared\DateRange;

interface SalesCalendar
{
    /**
     * @return list<SellingDay>
     */
    public function days(DateRange $period): array;

    /**
     * @return list<EventUnits>
     */
    public function unitsByEvent(DateRange $period): array;
}
