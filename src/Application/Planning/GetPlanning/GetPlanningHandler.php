<?php

declare(strict_types=1);

namespace App\Application\Planning\GetPlanning;

use App\Application\Planning\ReleaseView;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use App\Domain\Planning\StepState;
use App\Domain\Shared\BusinessTime;
use Psr\Clock\ClockInterface;

final readonly class GetPlanningHandler
{
    private const string WEEK_AHEAD = '+6 days';

    public function __construct(
        private ReleaseRepository $releases,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(): PlanningView
    {
        $today = $this->clock->now();
        $releases = array_map(static fn (Release $release): ReleaseView => ReleaseView::of($release, $today), $this->releases->all());
        usort($releases, static fn (ReleaseView $a, ReleaseView $b): int => $a->day <=> $b->day);
        $weekEnd = BusinessTime::day(BusinessTime::local($today)->modify(self::WEEK_AHEAD));

        $groups = ['late' => [], 'thisWeek' => [], 'later' => []];
        foreach ($releases as $release) {
            if ($release->past) {
                continue;
            }
            foreach ($release->steps as $step) {
                $group = match (StepState::from($step['state'])) {
                    StepState::Done => null,
                    StepState::Late => 'late',
                    StepState::Ongoing => 'thisWeek',
                    StepState::Upcoming => $step['start'] <= $weekEnd ? 'thisWeek' : 'later',
                };
                if (null !== $group) {
                    $groups[$group][] = $step + ['release' => ['id' => $release->id, 'day' => $release->day], 'owner' => $release->owner];
                }
            }
        }
        foreach ($groups as &$steps) {
            usort($steps, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        }
        unset($steps);

        return new PlanningView($groups['late'], $groups['thisWeek'], $groups['later'], $releases);
    }
}
