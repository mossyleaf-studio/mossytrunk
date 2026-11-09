<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class NonPositiveStepDuration extends InvalidRelease
{
    public function __construct(string $step)
    {
        parent::__construct('planning.non_positive_step_duration', ['step' => $step]);
    }
}
