<?php

declare(strict_types=1);

namespace App\Application\Planning\GetPlanning;

use App\Application\Planning\ReleaseView;
use App\Application\Planning\RestockView;

/**
 * @phpstan-type StepItem array{kind: 'step', label: string, days: int, done: bool, start: string, end: string, state: string, release: array{id: string, day: string}, owner: array{kind: string, id: string, name: string}}
 * @phpstan-type RestockItem array{kind: 'restock', id: string, supplier: array{id: string, name: string}, note: ?string, day: string, event: array{id: string, name: string}|null, leadDays: int, orderBy: string, ordered: bool, orderedOn: ?string, supplierOrder: array{id: string, reference: string}|null, state: string, past: bool}
 */
final readonly class PlanningView
{
    /**
     * @param list<StepItem|RestockItem> $late
     * @param list<StepItem|RestockItem> $thisWeek
     * @param list<StepItem|RestockItem> $later
     * @param list<ReleaseView>          $releases
     * @param list<RestockView>          $restocks
     */
    public function __construct(
        public array $late,
        public array $thisWeek,
        public array $later,
        public array $releases,
        public array $restocks,
    ) {
    }
}
