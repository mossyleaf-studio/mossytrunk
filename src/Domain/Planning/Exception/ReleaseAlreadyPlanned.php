<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class ReleaseAlreadyPlanned extends InvalidRelease
{
    public function __construct(string $name)
    {
        parent::__construct('planning.release_already_planned', ['name' => $name]);
    }
}
