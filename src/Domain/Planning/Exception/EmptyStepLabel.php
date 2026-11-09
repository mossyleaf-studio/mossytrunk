<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class EmptyStepLabel extends InvalidRelease
{
    public function __construct()
    {
        parent::__construct('planning.empty_step_label');
    }
}
