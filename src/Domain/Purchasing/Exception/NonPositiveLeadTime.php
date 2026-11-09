<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Exception;

final class NonPositiveLeadTime extends InvalidPurchase
{
    public function __construct()
    {
        parent::__construct('purchasing.non_positive_lead_time');
    }
}
