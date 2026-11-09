<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Shared\BusinessTime;

final class ReleaseSchedule
{
    /**
     * @param list<array{label: string, days: int, done: bool}> $steps
     *
     * @return list<ScheduledStep>
     */
    public static function of(\DateTimeImmutable $releaseDay, array $steps): array
    {
        $end = BusinessTime::midnightOf($releaseDay)->modify('-1 day');
        $schedule = [];
        foreach (array_reverse($steps) as $step) {
            $start = $end->modify(\sprintf('-%d days', $step['days'] - 1));
            array_unshift($schedule, new ScheduledStep($step['label'], $step['days'], $step['done'], $start, $end));
            $end = $start->modify('-1 day');
        }

        return $schedule;
    }
}
