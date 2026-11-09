<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class DayOrEventMissing extends InvalidPlanning
{
    public function __construct()
    {
        parent::__construct('planning.day_or_event_missing');
    }
}
