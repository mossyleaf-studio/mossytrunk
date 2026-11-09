<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Event\Exception\NegativeReadyMargin;

final class ReadyMargin
{
    public static function days(int $days): int
    {
        return $days >= 0 ? $days : throw new NegativeReadyMargin();
    }
}
