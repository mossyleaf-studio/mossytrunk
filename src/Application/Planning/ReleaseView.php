<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Domain\Planning\Release;
use App\Domain\Planning\ScheduledStep;
use App\Domain\Shared\BusinessTime;

final readonly class ReleaseView
{
    /**
     * @param array{kind: string, id: string, name: string}                                                $owner
     * @param array{id: string, name: string}|null                                                         $event
     * @param list<array{label: string, days: int, done: bool, start: string, end: string, state: string}> $steps
     */
    public function __construct(
        public string $id,
        public array $owner,
        public string $day,
        public ?array $event,
        public bool $past,
        public array $steps,
    ) {
    }

    public static function of(Release $release, PlanningDay $day): self
    {
        $today = $day->today;
        $event = $release->event();

        return new self(
            (string) $release->id(),
            self::ownerOf($release),
            BusinessTime::day($release->releaseDay()),
            null === $event ? null : ['id' => (string) $event->id(), 'name' => $event->name()],
            $release->isPastOn($today),
            array_map(static fn (ScheduledStep $step): array => [
                'label' => $step->label,
                'days' => $step->days,
                'done' => $step->done,
                'start' => BusinessTime::day($step->start),
                'end' => BusinessTime::day($step->end),
                'state' => $step->stateOn($today)->value,
            ], $release->scheduledSteps($day->readyDaysBefore)),
        );
    }

    /**
     * @return array{kind: string, id: string, name: string}
     */
    private static function ownerOf(Release $release): array
    {
        $collection = $release->collection();
        if (null !== $collection) {
            return ['kind' => 'collection', 'id' => (string) $collection->id(), 'name' => $collection->name()];
        }
        $design = $release->design() ?? throw new \LogicException();

        return ['kind' => 'design', 'id' => (string) $design->id(), 'name' => $design->name()];
    }
}
