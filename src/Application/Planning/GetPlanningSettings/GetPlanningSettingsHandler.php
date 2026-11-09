<?php

declare(strict_types=1);

namespace App\Application\Planning\GetPlanningSettings;

use App\Domain\Planning\PlanningSettings;
use App\Domain\Planning\PlanningSettingsRepository;

final readonly class GetPlanningSettingsHandler
{
    public function __construct(
        private PlanningSettingsRepository $settings,
    ) {
    }

    public function __invoke(): PlanningSettingsView
    {
        $settings = $this->settings->current();

        return new PlanningSettingsView($settings?->steps() ?? [], $settings?->readyDaysBefore() ?? PlanningSettings::DEFAULT_READY_DAYS_BEFORE);
    }
}
