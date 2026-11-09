<?php

declare(strict_types=1);

namespace App\Application\Planning\DefinePlanningSettings;

use App\Application\Transaction;
use App\Application\WorkspaceContext;
use App\Domain\Planning\PlanningSettings;
use App\Domain\Planning\PlanningSettingsRepository;

final readonly class DefinePlanningSettingsHandler
{
    public function __construct(
        private PlanningSettingsRepository $settings,
        private WorkspaceContext $workspace,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(DefinePlanningSettings $command): void
    {
        $settings = $this->settings->current();
        if (null === $settings) {
            $settings = PlanningSettings::of($this->workspace->current());
            $this->settings->add($settings);
        }
        $settings->define($command->steps, $command->readyDaysBefore);
        $this->transaction->commit();
    }
}
