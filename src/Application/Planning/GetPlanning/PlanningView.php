<?php

declare(strict_types=1);

namespace App\Application\Planning\GetPlanning;

use App\Application\Planning\ReleaseView;

final readonly class PlanningView
{
    /**
     * @param list<array{label: string, days: int, done: bool, start: string, end: string, state: string, release: array{id: string, day: string}, owner: array{kind: string, id: string, name: string}}> $late
     * @param list<array{label: string, days: int, done: bool, start: string, end: string, state: string, release: array{id: string, day: string}, owner: array{kind: string, id: string, name: string}}> $thisWeek
     * @param list<array{label: string, days: int, done: bool, start: string, end: string, state: string, release: array{id: string, day: string}, owner: array{kind: string, id: string, name: string}}> $later
     * @param list<ReleaseView>                                                                                                                                                                           $releases
     */
    public function __construct(
        public array $late,
        public array $thisWeek,
        public array $later,
        public array $releases,
    ) {
    }
}
