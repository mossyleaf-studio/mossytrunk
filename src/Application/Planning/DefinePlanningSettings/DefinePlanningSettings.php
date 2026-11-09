<?php

declare(strict_types=1);

namespace App\Application\Planning\DefinePlanningSettings;

final readonly class DefinePlanningSettings
{
    /**
     * @param list<array{label: string, days: int}> $steps
     */
    public function __construct(
        public array $steps,
        public int $readyDaysBefore,
    ) {
    }
}
