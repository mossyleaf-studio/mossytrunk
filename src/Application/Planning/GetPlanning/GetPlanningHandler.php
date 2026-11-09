<?php

declare(strict_types=1);

namespace App\Application\Planning\GetPlanning;

use App\Application\Planning\PlanningCalendar;
use App\Application\Planning\PlanningDay;
use App\Application\Planning\ReleaseView;
use App\Application\Planning\RestockView;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use App\Domain\Planning\Restock;
use App\Domain\Planning\RestockRepository;
use App\Domain\Planning\StepState;
use App\Domain\Shared\BusinessTime;

/**
 * @phpstan-import-type StepItem from PlanningView
 * @phpstan-import-type RestockItem from PlanningView
 * @phpstan-import-type EventItem from PlanningView
 */
final readonly class GetPlanningHandler
{
    private const string WEEK_AHEAD = '+6 days';

    public function __construct(
        private ReleaseRepository $releases,
        private RestockRepository $restocks,
        private EventRepository $events,
        private PlanningCalendar $calendar,
    ) {
    }

    public function __invoke(): PlanningView
    {
        $day = $this->calendar->today();
        $today = $day->today;
        $releases = array_map(static fn (Release $release): ReleaseView => ReleaseView::of($release, $day), $this->releases->all());
        usort($releases, static fn (ReleaseView $a, ReleaseView $b): int => $a->day <=> $b->day);
        $restocks = array_values(array_filter(
            array_map(static fn (Restock $restock): RestockView => RestockView::of($restock, $day), $this->restocks->all()),
            static fn (RestockView $restock): bool => !$restock->past,
        ));
        usort($restocks, static fn (RestockView $a, RestockView $b): int => [$a->orderBy, $a->day] <=> [$b->orderBy, $b->day]);
        $todayDay = BusinessTime::day($today);
        $weekEnd = BusinessTime::day(BusinessTime::local($today)->modify(self::WEEK_AHEAD));

        /** @var array{late: list<StepItem|RestockItem>, thisWeek: list<StepItem|RestockItem>, later: list<StepItem|RestockItem>} $groups */
        $groups = ['late' => [], 'thisWeek' => [], 'later' => []];
        foreach ($releases as $release) {
            if ($release->past) {
                continue;
            }
            foreach ($release->steps as $step) {
                $group = self::groupOf(StepState::from($step['state']), $step['start'], $weekEnd);
                if (null !== $group) {
                    $groups[$group][] = ['kind' => 'step'] + $step + ['release' => ['id' => $release->id, 'day' => $release->day], 'owner' => $release->owner];
                }
            }
        }
        foreach ($restocks as $restock) {
            $group = self::restockGroupOf(StepState::from($restock->state), $restock->orderBy, $todayDay, $weekEnd);
            if (null !== $group) {
                $groups[$group][] = $restock->item();
            }
        }
        foreach ($groups as &$items) {
            usort($items, static fn (array $a, array $b): int => self::sortKey($a) <=> self::sortKey($b));
        }
        unset($items);

        return new PlanningView($todayDay, $this->upcomingEvents($day), $groups['late'], $groups['thisWeek'], $groups['later'], $releases, $restocks);
    }

    /**
     * @return list<EventItem>
     */
    private function upcomingEvents(PlanningDay $day): array
    {
        return array_map(static fn (Event $event): array => [
            'id' => (string) $event->id(),
            'name' => $event->name(),
            'start' => $event->period()->start()->format('Y-m-d'),
            'end' => $event->period()->end()->format('Y-m-d'),
            'readyBy' => $day->readyByOf($event),
            'readyDaysBefore' => $day->readyDaysBeforeOf($event),
        ], $this->events->notOverOn($day->today));
    }

    /**
     * @return 'late'|'thisWeek'|'later'|null
     */
    private static function groupOf(StepState $state, string $start, string $weekEnd): ?string
    {
        return match ($state) {
            StepState::Done => null,
            StepState::Late => 'late',
            StepState::Ongoing => 'thisWeek',
            StepState::Upcoming => $start <= $weekEnd ? 'thisWeek' : 'later',
        };
    }

    /**
     * @return 'late'|'thisWeek'|'later'|null
     */
    private static function restockGroupOf(StepState $state, string $orderBy, string $today, string $weekEnd): ?string
    {
        return match (true) {
            StepState::Done !== $state => self::groupOf($state, $orderBy, $weekEnd),
            $orderBy < $today => null,
            default => self::groupOf(StepState::Upcoming, $orderBy, $weekEnd),
        };
    }

    /**
     * @param StepItem|RestockItem $item
     *
     * @return array{string, string}
     */
    private static function sortKey(array $item): array
    {
        return 'step' === $item['kind'] ? [$item['start'], $item['end']] : [$item['orderBy'], $item['orderBy']];
    }
}
