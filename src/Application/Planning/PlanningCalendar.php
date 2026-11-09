<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Domain\Planning\PlanningSettings;
use App\Domain\Planning\PlanningSettingsRepository;
use Psr\Clock\ClockInterface;

final readonly class PlanningCalendar
{
    public function __construct(
        private PlanningSettingsRepository $settings,
        private ClockInterface $clock,
    ) {
    }

    public function today(): PlanningDay
    {
        return new PlanningDay(
            $this->clock->now(),
            $this->settings->current()?->readyDaysBefore() ?? PlanningSettings::DEFAULT_READY_DAYS_BEFORE,
        );
    }
}
