<?php

declare(strict_types=1);

namespace App\Domain\Planning;

interface PlanningSettingsRepository
{
    public function current(): ?PlanningSettings;

    public function add(PlanningSettings $settings): void;
}
