<?php

declare(strict_types=1);

namespace App\Application\Integration\ImportSales;

enum SaleOutcome
{
    case Imported;
    case WithoutItems;
    case WithoutEvent;
    case WaitingForItems;
}
