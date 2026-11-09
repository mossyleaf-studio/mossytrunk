<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class ReleaseWithoutDay extends InvalidRelease
{
    public function __construct()
    {
        parent::__construct('planning.release_without_day');
    }
}
