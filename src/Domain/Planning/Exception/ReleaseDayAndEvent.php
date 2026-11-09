<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class ReleaseDayAndEvent extends InvalidRelease
{
    public function __construct()
    {
        parent::__construct('planning.release_day_and_event');
    }
}
