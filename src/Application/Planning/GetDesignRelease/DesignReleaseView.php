<?php

declare(strict_types=1);

namespace App\Application\Planning\GetDesignRelease;

use App\Application\Planning\ReleaseView;

final readonly class DesignReleaseView
{
    public function __construct(
        public ?ReleaseView $release,
        public bool $inherited,
    ) {
    }
}
