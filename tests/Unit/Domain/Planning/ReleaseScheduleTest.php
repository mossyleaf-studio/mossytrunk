<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Planning;

use App\Domain\Planning\ReleaseSchedule;
use App\Domain\Planning\ScheduledStep;
use App\Domain\Planning\StepState;
use App\Domain\Shared\BusinessTime;
use PHPUnit\Framework\TestCase;

final class ReleaseScheduleTest extends TestCase
{
    public function testTheStepsChainBackwardEndingOnTheGivenDay(): void
    {
        $schedule = ReleaseSchedule::endingOn(BusinessTime::at('2026-07-08 15:30'), [
            ['label' => 'Dessin', 'days' => 3, 'done' => false],
            ['label' => 'Impression', 'days' => 2, 'done' => true],
            ['label' => 'Emballage', 'days' => 1, 'done' => false],
        ]);

        self::assertSame([
            ['Dessin', 3, false, '2026-07-03', '2026-07-05'],
            ['Impression', 2, true, '2026-07-06', '2026-07-07'],
            ['Emballage', 1, false, '2026-07-08', '2026-07-08'],
        ], self::summary($schedule));
    }

    public function testOneDayStepsTakeOneDayEach(): void
    {
        $schedule = ReleaseSchedule::endingOn(BusinessTime::at('2026-07-08'), [
            ['label' => 'Relecture', 'days' => 1, 'done' => false],
            ['label' => 'Envoi', 'days' => 1, 'done' => false],
        ]);

        self::assertSame([
            ['Relecture', 1, false, '2026-07-07', '2026-07-07'],
            ['Envoi', 1, false, '2026-07-08', '2026-07-08'],
        ], self::summary($schedule));
    }

    public function testDaysStayParisMidnightsAcrossTheSpringClockChange(): void
    {
        $schedule = ReleaseSchedule::endingOn(BusinessTime::at('2026-03-30'), [
            ['label' => 'Dessin', 'days' => 5, 'done' => false],
        ]);

        self::assertSame('2026-03-26 00:00 Europe/Paris', $schedule[0]->start->format('Y-m-d H:i e'));
        self::assertSame('2026-03-30 00:00 Europe/Paris', $schedule[0]->end->format('Y-m-d H:i e'));
    }

    public function testDaysStayParisMidnightsAcrossTheAutumnClockChange(): void
    {
        $schedule = ReleaseSchedule::endingOn(BusinessTime::at('2026-10-26'), [
            ['label' => 'Dessin', 'days' => 4, 'done' => false],
        ]);

        self::assertSame('2026-10-23 00:00 Europe/Paris', $schedule[0]->start->format('Y-m-d H:i e'));
        self::assertSame('2026-10-26 00:00 Europe/Paris', $schedule[0]->end->format('Y-m-d H:i e'));
    }

    public function testAStepStateDependsOnToday(): void
    {
        [$step] = ReleaseSchedule::endingOn(BusinessTime::at('2026-07-08'), [
            ['label' => 'Impression', 'days' => 3, 'done' => false],
        ]);

        self::assertSame(StepState::Upcoming, $step->stateOn(BusinessTime::at('2026-07-05 23:00')));
        self::assertSame(StepState::Ongoing, $step->stateOn(BusinessTime::at('2026-07-06 08:00')));
        self::assertSame(StepState::Ongoing, $step->stateOn(BusinessTime::at('2026-07-08 23:59')));
        self::assertSame(StepState::Late, $step->stateOn(BusinessTime::at('2026-07-09 00:00')));
    }

    public function testADoneStepIsDoneWhateverTheDay(): void
    {
        [$step] = ReleaseSchedule::endingOn(BusinessTime::at('2026-07-08'), [
            ['label' => 'Impression', 'days' => 3, 'done' => true],
        ]);

        self::assertSame(StepState::Done, $step->stateOn(BusinessTime::at('2026-07-01')));
        self::assertSame(StepState::Done, $step->stateOn(BusinessTime::at('2026-07-20')));
    }

    public function testAReleaseWithoutStepsHasNoSchedule(): void
    {
        self::assertSame([], ReleaseSchedule::endingOn(BusinessTime::at('2026-07-08'), []));
    }

    /**
     * @param list<ScheduledStep> $schedule
     *
     * @return list<array{string, int, bool, string, string}>
     */
    private static function summary(array $schedule): array
    {
        return array_map(static fn (ScheduledStep $step): array => [$step->label, $step->days, $step->done, $step->start->format('Y-m-d'), $step->end->format('Y-m-d')], $schedule);
    }
}
