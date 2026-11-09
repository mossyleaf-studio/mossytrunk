<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Planning\Exception\DuplicateStepLabel;
use App\Domain\Planning\Exception\EmptyStepLabel;
use App\Domain\Planning\Exception\NonPositiveStepDuration;

final class StepList
{
    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     *
     * @return list<array{label: string, days: int, done: bool}>
     */
    public static function clean(array $steps): array
    {
        $clean = [];
        foreach ($steps as $step) {
            $label = trim($step['label']);
            if ('' === $label) {
                throw new EmptyStepLabel();
            }
            if (\in_array($label, array_column($clean, 'label'), true)) {
                throw new DuplicateStepLabel($label);
            }
            if ($step['days'] < 1) {
                throw new NonPositiveStepDuration($label);
            }
            $clean[] = ['label' => $label, 'days' => $step['days'], 'done' => $step['done'] ?? false];
        }

        return $clean;
    }
}
