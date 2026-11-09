<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class DayAndEvent extends InvalidPlanning
{
    public function __construct()
    {
        parent::__construct('planning.day_and_event');
    }
}
