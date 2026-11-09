<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Planning;

use App\Domain\Design\Design;
use App\Domain\Design\DesignCollection;
use App\Domain\Event\Event;
use App\Domain\Planning\Exception\DayAndEvent;
use App\Domain\Planning\Exception\DayOrEventMissing;
use App\Domain\Planning\Exception\DuplicateStepLabel;
use App\Domain\Planning\Exception\EmptyStepLabel;
use App\Domain\Planning\Exception\NonPositiveStepDuration;
use App\Domain\Planning\Exception\UnknownStep;
use App\Domain\Planning\Release;
use App\Domain\Planning\ScheduledStep;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    private const array STEPS = [
        ['label' => 'Dessin', 'days' => 3],
        ['label' => 'Impression', 'days' => 2],
    ];

    public function testACollectionReleasesOnADay(): void
    {
        $collection = DesignCollection::start(TestWorkspace::get(), 'Forêt');

        $release = Release::ofCollection($collection, BusinessTime::at('2026-07-09 14:00'), null, self::STEPS);

        self::assertSame($collection, $release->collection());
        self::assertNull($release->design());
        self::assertNull($release->event());
        self::assertSame(TestWorkspace::get(), $release->workspace());
        self::assertSame('2026-07-09', $release->day()?->format('Y-m-d'));
        self::assertSame('2026-07-09 00:00', $release->releaseDay()->format('Y-m-d H:i'));
        self::assertSame([
            ['label' => 'Dessin', 'days' => 3, 'done' => false],
            ['label' => 'Impression', 'days' => 2, 'done' => false],
        ], $release->steps());
    }

    public function testADesignReleasesAtAnEventAndFollowsItsReschedule(): void
    {
        $design = Design::start(TestWorkspace::get(), 'Renard');
        $event = self::event('2026-07-09', '2026-07-12');

        $release = Release::ofDesign($design, null, $event, self::STEPS);

        self::assertSame($design, $release->design());
        self::assertNull($release->collection());
        self::assertSame($event, $release->event());
        self::assertNull($release->day());
        self::assertSame('2026-07-09', $release->releaseDay()->format('Y-m-d'));

        $event->reschedule(DateRange::fromDates(BusinessTime::at('2026-08-01'), BusinessTime::at('2026-08-02')));

        self::assertSame('2026-08-01', $release->releaseDay()->format('Y-m-d'));
        self::assertSame(['2026-07-27', '2026-07-30'], array_map(static fn (ScheduledStep $step): string => $step->start->format('Y-m-d'), $release->scheduledSteps()));
    }

    public function testADesignReleasesOnADay(): void
    {
        $design = Design::start(TestWorkspace::get(), 'Renard');

        $release = Release::ofDesign($design, BusinessTime::at('2026-07-09 14:00'), null, self::STEPS);

        self::assertSame($design, $release->design());
        self::assertNull($release->collection());
        self::assertNull($release->event());
        self::assertSame('2026-07-09 00:00', $release->releaseDay()->format('Y-m-d H:i'));
    }

    public function testAReleaseNeedsADayOrAnEvent(): void
    {
        $this->expectException(DayOrEventMissing::class);

        Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), null, null, self::STEPS);
    }

    public function testAReleaseCannotHaveBothADayAndAnEvent(): void
    {
        $this->expectException(DayAndEvent::class);

        Release::ofDesign(Design::start(TestWorkspace::get(), 'Renard'), BusinessTime::at('2026-07-09'), self::event('2026-07-09', '2026-07-12'), self::STEPS);
    }

    public function testRescheduleMovesFromADayToAnEvent(): void
    {
        $release = Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, self::STEPS);
        $event = self::event('2026-09-05', '2026-09-06');

        $release->schedule(null, $event);

        self::assertNull($release->day());
        self::assertSame($event, $release->event());
        self::assertSame('2026-09-05', $release->releaseDay()->format('Y-m-d'));
    }

    public function testRescheduleStillNeedsADayOrAnEvent(): void
    {
        $release = Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, self::STEPS);

        $this->expectException(DayOrEventMissing::class);

        $release->schedule(null, null);
    }

    public function testStepLabelsAreTrimmed(): void
    {
        $release = Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, [['label' => '  Dessin ', 'days' => 1]]);

        self::assertSame([['label' => 'Dessin', 'days' => 1, 'done' => false]], $release->steps());
    }

    public function testAStepNeedsALabel(): void
    {
        $this->expectException(EmptyStepLabel::class);

        Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, [['label' => '  ', 'days' => 1]]);
    }

    public function testStepLabelsAreUnique(): void
    {
        $this->expectExceptionObject(new DuplicateStepLabel('Dessin'));

        Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, [['label' => 'Dessin', 'days' => 1], ['label' => ' Dessin', 'days' => 2]]);
    }

    public function testAStepLastsAtLeastOneDay(): void
    {
        $this->expectExceptionObject(new NonPositiveStepDuration('Dessin'));

        Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, [['label' => 'Dessin', 'days' => 0]]);
    }

    public function testTickingAStepMarksItDoneThenUndone(): void
    {
        $release = Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, self::STEPS);

        $release->tick('Impression', true);

        self::assertSame([false, true], array_column($release->steps(), 'done'));

        $release->tick('Impression', false);

        self::assertSame([false, false], array_column($release->steps(), 'done'));
    }

    public function testTickingAnUnknownStepFails(): void
    {
        $release = Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, self::STEPS);

        $this->expectExceptionObject(new UnknownStep('Vernissage'));

        $release->tick('Vernissage', true);
    }

    public function testReplanKeepsTheGivenDoneFlags(): void
    {
        $release = Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, self::STEPS);
        $release->tick('Dessin', true);

        $release->replan([
            ['label' => 'Impression', 'days' => 4, 'done' => true],
            ['label' => 'Dessin', 'days' => 2, 'done' => false],
            ['label' => 'Emballage', 'days' => 1],
        ]);

        self::assertSame([
            ['label' => 'Impression', 'days' => 4, 'done' => true],
            ['label' => 'Dessin', 'days' => 2, 'done' => false],
            ['label' => 'Emballage', 'days' => 1, 'done' => false],
        ], $release->steps());
    }

    public function testAReleaseIsPastOnceItsDayIsBehindToday(): void
    {
        $release = Release::ofCollection(DesignCollection::start(TestWorkspace::get(), 'Forêt'), BusinessTime::at('2026-07-09'), null, self::STEPS);

        self::assertFalse($release->isPastOn(BusinessTime::at('2026-07-08 23:00')));
        self::assertFalse($release->isPastOn(BusinessTime::at('2026-07-09 22:00')));
        self::assertTrue($release->isPastOn(BusinessTime::at('2026-07-10 00:30')));
    }

    private static function event(string $start, string $end): Event
    {
        return Event::schedule(TestWorkspace::get(), 'Japan Expo', 'Villepinte', DateRange::fromDates(BusinessTime::at($start), BusinessTime::at($end)));
    }
}
