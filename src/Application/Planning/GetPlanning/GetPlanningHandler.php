<?php

declare(strict_types=1);

namespace App\Application\Planning\GetPlanning;

use App\Application\Planning\ReleaseView;
use App\Application\Planning\RestockView;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use App\Domain\Planning\Restock;
use App\Domain\Planning\RestockRepository;
use App\Domain\Planning\StepState;
use App\Domain\Shared\BusinessTime;
use Psr\Clock\ClockInterface;

/**
 * @phpstan-import-type StepItem from PlanningView
 * @phpstan-import-type RestockItem from PlanningView
 */
final readonly class GetPlanningHandler
{
    private const string WEEK_AHEAD = '+6 days';

    public function __construct(
        private ReleaseRepository $releases,
        private RestockRepository $restocks,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(): PlanningView
    {
        $today = $this->clock->now();
        $releases = array_map(static fn (Release $release): ReleaseView => ReleaseView::of($release, $today), $this->releases->all());
        usort($releases, static fn (ReleaseView $a, ReleaseView $b): int => $a->day <=> $b->day);
        $restocks = array_values(array_filter(
            array_map(static fn (Restock $restock): RestockView => RestockView::of($restock, $today), $this->restocks->all()),
            static fn (RestockView $restock): bool => !$restock->past,
        ));
        usort($restocks, static fn (RestockView $a, RestockView $b): int => [$a->orderBy, $a->day] <=> [$b->orderBy, $b->day]);
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
            $group = self::groupOf(StepState::from($restock->state), $restock->orderBy, $weekEnd);
            if (null !== $group) {
                $groups[$group][] = $restock->item();
            }
        }
        foreach ($groups as &$items) {
            usort($items, static fn (array $a, array $b): int => self::sortKey($a) <=> self::sortKey($b));
        }
        unset($items);

        return new PlanningView($groups['late'], $groups['thisWeek'], $groups['later'], $releases, $restocks);
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
     * @param StepItem|RestockItem $item
     *
     * @return array{string, string}
     */
    private static function sortKey(array $item): array
    {
        return 'step' === $item['kind'] ? [$item['start'], $item['end']] : [$item['orderBy'], $item['orderBy']];
    }
}
