<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class DuplicateStepLabel extends InvalidRelease
{
    public function __construct(string $step)
    {
        parent::__construct('planning.duplicate_step_label', ['step' => $step]);
    }
}
