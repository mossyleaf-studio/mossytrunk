<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class UnknownStep extends InvalidRelease
{
    public function __construct(string $step)
    {
        parent::__construct('planning.unknown_step', ['step' => $step]);
    }
}
