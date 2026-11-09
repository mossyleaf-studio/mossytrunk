<?php

declare(strict_types=1);

namespace App\Application\Planning\GetPlanningSettings;

final readonly class PlanningSettingsView
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
