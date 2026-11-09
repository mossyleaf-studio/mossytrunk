<?php

declare(strict_types=1);

namespace App\Domain\Planning;

enum StepState: string
{
    case Done = 'done';
    case Late = 'late';
    case Ongoing = 'ongoing';
    case Upcoming = 'upcoming';
}
