<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Purchasing\Exception\NonPositiveLeadTime;

final class LeadTime
{
    public static function days(int $days): int
    {
        return $days >= 1 ? $days : throw new NonPositiveLeadTime();
    }
}
