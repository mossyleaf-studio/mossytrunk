<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Planning;

use App\Application\Design\CreateCollection\CreateCollectionHandler;
use App\Application\Design\CreateDesign\CreateDesign;
use App\Application\Design\CreateDesign\CreateDesignHandler;
use App\Application\Design\DeleteCollection\DeleteCollectionHandler;
use App\Application\Design\DeleteDesign\DeleteDesignHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Event\UpdateEvent\UpdateEvent;
use App\Application\Event\UpdateEvent\UpdateEventHandler;
use App\Application\Planning\CancelRelease\CancelReleaseHandler;
use App\Application\Planning\DefinePlanningSettings\DefinePlanningSettings;
use App\Application\Planning\DefinePlanningSettings\DefinePlanningSettingsHandler;
use App\Application\Planning\GetDesignRelease\GetDesignReleaseHandler;
use App\Application\Planning\GetPlanning\GetPlanningHandler;
use App\Application\Planning\GetPlanning\PlanningView;
use App\Application\Planning\GetPlanningSettings\GetPlanningSettingsHandler;
use App\Application\Planning\PlanCollectionRelease\PlanCollectionRelease;
use App\Application\Planning\PlanCollectionRelease\PlanCollectionReleaseHandler;
use App\Application\Planning\PlanDesignRelease\PlanDesignRelease;
use App\Application\Planning\PlanDesignRelease\PlanDesignReleaseHandler;
use App\Application\Planning\ReleaseView;
use App\Application\Planning\ReplanRelease\ReplanRelease;
use App\Application\Planning\ReplanRelease\ReplanReleaseHandler;
use App\Application\Planning\TickReleaseStep\TickReleaseStepHandler;
use App\Domain\Event\Exception\NegativeReadyMargin;
use App\Domain\Planning\Exception\DayAndEvent;
use App\Domain\Planning\Exception\ReleaseAlreadyPlanned;
use App\Domain\Shared\Exception\NotFound;
use App\Tests\Support\ActsAsUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PlanningUseCasesTest extends KernelTestCase
{
    use ActsAsUser;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        self::mockTime('2026-11-09 10:00');
    }

    public function testThePlanningSettingsAreDefinedThenRedefined(): void
    {
        $get = self::getContainer()->get(GetPlanningSettingsHandler::class);

        self::assertSame([[], 3], [$get()->steps, $get()->readyDaysBefore]);

        $this->defineSettings([['label' => ' Dessins ', 'days' => 5], ['label' => 'Impression', 'days' => 3]], 5);
        $this->clear();
        self::assertSame([[['label' => 'Dessins', 'days' => 5], ['label' => 'Impression', 'days' => 3]], 5], [$get()->steps, $get()->readyDaysBefore]);

        $this->defineSettings([['label' => 'Photos', 'days' => 2]], 0);
        $this->clear();
        self::assertSame([[['label' => 'Photos', 'days' => 2]], 0], [$get()->steps, $get()->readyDaysBefore]);
    }

    public function testANegativeDefaultMarginIsRefused(): void
    {
        $this->expectException(NegativeReadyMargin::class);
        $this->defineSettings([], -1);
    }

    public function testACollectionIsPlannedOnADay(): void
    {
        $collectionId = $this->collection('Sous-bois');

        $releaseId = $this->planCollection(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-11-20'), null, [
            ['label' => 'Dessins', 'days' => 5],
            ['label' => 'Impression', 'days' => 3],
        ]));
        $this->clear();

        $release = $this->releases()[0];
        self::assertSame($releaseId, $release->id);
        self::assertSame(['kind' => 'collection', 'id' => $collectionId, 'name' => 'Sous-bois'], $release->owner);
        self::assertSame('2026-11-20', $release->day);
        self::assertNull($release->event);
        self::assertFalse($release->past);
        self::assertSame([
            ['label' => 'Dessins', 'days' => 5, 'done' => false, 'start' => '2026-11-12', 'end' => '2026-11-16', 'state' => 'upcoming'],
            ['label' => 'Impression', 'days' => 3, 'done' => false, 'start' => '2026-11-17', 'end' => '2026-11-19', 'state' => 'upcoming'],
        ], $release->steps);
    }

    public function testAReleaseOnAnEventFollowsItsReschedule(): void
    {
        $eventId = (string) self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-06')));
        $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), null, $eventId, [['label' => 'Impression', 'days' => 3]]));
        $this->clear();

        $release = $this->releases()[0];
        self::assertSame(['id' => $eventId, 'name' => 'Marché de Noël'], $release->event);
        self::assertSame('2026-12-05', $release->day);
        self::assertSame(['2026-11-30', '2026-12-02'], [$release->steps[0]['start'], $release->steps[0]['end']]);

        self::getContainer()->get(UpdateEventHandler::class)(new UpdateEvent($eventId, 'Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-12'), new \DateTimeImmutable('2026-12-13')));
        $this->clear();

        $release = $this->releases()[0];
        self::assertSame('2026-12-12', $release->day);
        self::assertSame(['label' => 'Impression', 'days' => 3, 'done' => false, 'start' => '2026-12-07', 'end' => '2026-12-09', 'state' => 'upcoming'], $release->steps[0]);
    }

    public function testAReleaseOnAnEventEndsOnItsReadyByDayWhateverItsMargin(): void
    {
        $this->defineSettings([], 5);
        $eventId = $this->event();
        $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), null, $eventId, [['label' => 'Impression', 'days' => 3]]));
        $this->clear();

        self::assertSame(['2026-11-28', '2026-11-30'], [$this->releases()[0]->steps[0]['start'], $this->releases()[0]->steps[0]['end']]);

        self::getContainer()->get(UpdateEventHandler::class)(new UpdateEvent($eventId, 'Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-06'), 0));
        $this->clear();

        self::assertSame(['2026-12-03', '2026-12-05'], [$this->releases()[0]->steps[0]['start'], $this->releases()[0]->steps[0]['end']]);
    }

    public function testAReleaseOnADayIgnoresTheMargin(): void
    {
        $this->defineSettings([], 5);
        $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, [['label' => 'Impression', 'days' => 3]]));
        $this->clear();

        self::assertSame(['2026-11-17', '2026-11-19'], [$this->releases()[0]->steps[0]['start'], $this->releases()[0]->steps[0]['end']]);
    }

    public function testThePlanningTellsTodayAndTheEventsNotOverYet(): void
    {
        $schedule = self::getContainer()->get(ScheduleEventHandler::class);
        $schedule(new ScheduleEvent('Salon passé', 'Lyon', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-02')));
        $ongoing = (string) $schedule(new ScheduleEvent('Salon en cours', 'Lyon', new \DateTimeImmutable('2026-11-08'), new \DateTimeImmutable('2026-11-09')));
        $christmas = (string) $schedule(new ScheduleEvent('Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-06'), 1));
        $this->clear();

        $planning = $this->planning();

        self::assertSame('2026-11-09', $planning->today);
        self::assertSame([
            ['id' => $ongoing, 'name' => 'Salon en cours', 'start' => '2026-11-08', 'end' => '2026-11-09', 'readyBy' => '2026-11-05', 'readyDaysBefore' => 3],
            ['id' => $christmas, 'name' => 'Marché de Noël', 'start' => '2026-12-05', 'end' => '2026-12-06', 'readyBy' => '2026-12-04', 'readyDaysBefore' => 1],
        ], $planning->events);

        self::actAsMemberOf('Autre atelier');
        self::assertSame([], $this->planning()->events);
    }

    public function testAnOwnerHasOneReleaseOnly(): void
    {
        $collectionId = $this->collection('Sous-bois');
        $this->planCollection(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-11-20'), null, []));

        $this->expectExceptionObject(new ReleaseAlreadyPlanned('Sous-bois'));
        $this->planCollection(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-12-20'), null, []));
    }

    public function testAReleaseIsReplannedOnAnotherMomentAndSteps(): void
    {
        $eventId = (string) self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-06')));
        $releaseId = $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, [['label' => 'Dessins', 'days' => 5]]));

        self::getContainer()->get(ReplanReleaseHandler::class)(new ReplanRelease($releaseId, null, $eventId, [
            ['label' => 'Photos', 'days' => 2, 'done' => true],
            ['label' => 'Annonce', 'days' => 1],
        ]));
        $this->clear();

        $release = $this->releases()[0];
        self::assertSame('2026-12-05', $release->day);
        self::assertSame(['id' => $eventId, 'name' => 'Marché de Noël'], $release->event);
        self::assertSame([
            ['label' => 'Photos', 'days' => 2, 'done' => true, 'start' => '2026-11-30', 'end' => '2026-12-01', 'state' => 'done'],
            ['label' => 'Annonce', 'days' => 1, 'done' => false, 'start' => '2026-12-02', 'end' => '2026-12-02', 'state' => 'upcoming'],
        ], $release->steps);
    }

    public function testAReleaseIsCancelled(): void
    {
        $releaseId = $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, []));

        self::getContainer()->get(CancelReleaseHandler::class)($releaseId);
        $this->clear();

        self::assertSame([], $this->releases());
    }

    public function testAStepIsTickedThenUnticked(): void
    {
        $releaseId = $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, [['label' => 'Dessins', 'days' => 5]]));
        $tick = self::getContainer()->get(TickReleaseStepHandler::class);

        $tick($releaseId, 'Dessins', true);
        $this->clear();
        self::assertSame([true, 'done'], [$this->releases()[0]->steps[0]['done'], $this->releases()[0]->steps[0]['state']]);

        $tick($releaseId, 'Dessins', false);
        $this->clear();
        self::assertFalse($this->releases()[0]->steps[0]['done']);
    }

    public function testADesignInheritsItsCollectionReleaseUnlessItHasItsOwn(): void
    {
        $collectionId = $this->collection('Sous-bois');
        $fern = $this->design('Fougère', $collectionId);
        $heron = $this->design('Héron');
        $this->planCollection(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-11-20'), null, []));
        $get = self::getContainer()->get(GetDesignReleaseHandler::class);
        $this->clear();

        $inherited = $get($fern);
        self::assertTrue($inherited->inherited);
        self::assertSame(['kind' => 'collection', 'id' => $collectionId, 'name' => 'Sous-bois'], $inherited->release?->owner);

        $this->planDesign(new PlanDesignRelease($fern, new \DateTimeImmutable('2026-12-01'), null, []));
        $this->clear();
        $own = $get($fern);
        self::assertFalse($own->inherited);
        self::assertSame(['kind' => 'design', 'id' => $fern, 'name' => 'Fougère'], $own->release?->owner);
        self::assertSame('2026-12-01', $own->release->day);

        $standalone = $get($heron);
        self::assertNull($standalone->release);
        self::assertFalse($standalone->inherited);
    }

    public function testThePlanningGroupsTheStepsLeftToDo(): void
    {
        $woods = $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, [
            ['label' => 'Dessins', 'days' => 5],
            ['label' => 'Impression', 'days' => 3],
            ['label' => 'Photos', 'days' => 2],
            ['label' => 'Annonce', 'days' => 1],
        ]));
        $fern = $this->design('Fougère');
        $fernRelease = $this->planDesign(new PlanDesignRelease($fern, new \DateTimeImmutable('2026-11-10'), null, [
            ['label' => 'Croquis', 'days' => 2, 'done' => true],
            ['label' => 'Encrage', 'days' => 1],
            ['label' => 'Vernis', 'days' => 1],
        ]));
        $this->planDesign(new PlanDesignRelease($this->design('Mousse'), new \DateTimeImmutable('2026-11-01'), null, [['label' => 'Tirage', 'days' => 2]]));
        $this->clear();

        $planning = $this->planning();

        self::assertSame([
            ['kind' => 'step', 'label' => 'Encrage', 'days' => 1, 'done' => false, 'start' => '2026-11-08', 'end' => '2026-11-08', 'state' => 'late', 'release' => ['id' => $fernRelease, 'day' => '2026-11-10'], 'owner' => ['kind' => 'design', 'id' => $fern, 'name' => 'Fougère']],
        ], $planning->late);
        self::assertSame(['Vernis', 'Dessins', 'Impression'], array_column($planning->thisWeek, 'label'));
        self::assertSame(['step', 'step', 'step'], array_column($planning->thisWeek, 'kind'));
        self::assertSame(['ongoing', 'ongoing', 'upcoming'], array_column($planning->thisWeek, 'state'));
        self::assertSame(['id' => $woods, 'day' => '2026-11-20'], $planning->thisWeek[1]['release'] ?? null);
        self::assertSame(['Photos', 'Annonce'], array_column($planning->later, 'label'));
        self::assertSame(['Mousse', 'Fougère', 'Sous-bois'], array_map(static fn (ReleaseView $release): string => $release->owner['name'], $planning->releases));
        self::assertSame([true, false, false], array_map(static fn (ReleaseView $release): bool => $release->past, $planning->releases));
    }

    public function testReleasesOfAnotherWorkspaceAreHidden(): void
    {
        $releaseId = $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, [['label' => 'Dessins', 'days' => 5]]));

        self::actAsMemberOf('Autre atelier');

        self::assertSame([], $this->releases());
        $this->expectException(NotFound::class);
        self::getContainer()->get(TickReleaseStepHandler::class)($releaseId, 'Dessins', true);
    }

    public function testACollectionOfAnotherWorkspaceCannotBePlanned(): void
    {
        $collectionId = $this->collection('Sous-bois');

        self::actAsMemberOf('Autre atelier');

        $this->expectException(NotFound::class);
        $this->planCollection(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-11-20'), null, []));
    }

    public function testADesignOfAnotherWorkspaceCannotBePlanned(): void
    {
        $designId = $this->design('Héron');

        self::actAsMemberOf('Autre atelier');

        $this->expectException(NotFound::class);
        $this->planDesign(new PlanDesignRelease($designId, new \DateTimeImmutable('2026-11-20'), null, []));
    }

    public function testAnEventOfAnotherWorkspaceCannotBeUsed(): void
    {
        $eventId = $this->event();

        self::actAsMemberOf('Autre atelier');

        $this->expectException(NotFound::class);
        $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), null, $eventId, []));
    }

    public function testAReleaseCannotBeOnADayAndAnEvent(): void
    {
        $eventId = $this->event();

        $this->expectException(DayAndEvent::class);
        $this->planDesign(new PlanDesignRelease($this->design('Héron'), new \DateTimeImmutable('2026-11-20'), $eventId, []));
    }

    public function testAReleaseOfAnotherWorkspaceCannotBeReplanned(): void
    {
        $releaseId = $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, []));

        self::actAsMemberOf('Autre atelier');

        $this->expectException(NotFound::class);
        self::getContainer()->get(ReplanReleaseHandler::class)(new ReplanRelease($releaseId, new \DateTimeImmutable('2026-12-20'), null, []));
    }

    public function testAReleaseOfAnotherWorkspaceCannotBeCancelled(): void
    {
        $releaseId = $this->planCollection(new PlanCollectionRelease($this->collection('Sous-bois'), new \DateTimeImmutable('2026-11-20'), null, []));

        self::actAsMemberOf('Autre atelier');

        $this->expectException(NotFound::class);
        self::getContainer()->get(CancelReleaseHandler::class)($releaseId);
    }

    public function testDeletingTheOwnerRemovesItsRelease(): void
    {
        $collectionId = $this->collection('Sous-bois');
        $designId = $this->design('Héron');
        $this->planCollection(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-11-20'), null, []));
        $this->planDesign(new PlanDesignRelease($designId, new \DateTimeImmutable('2026-11-25'), null, []));
        $this->clear();

        self::getContainer()->get(DeleteCollectionHandler::class)($collectionId);
        self::getContainer()->get(DeleteDesignHandler::class)($designId);
        $this->clear();

        self::assertSame([], $this->releases());
    }

    private function collection(string $name): string
    {
        return (string) self::getContainer()->get(CreateCollectionHandler::class)($name, null);
    }

    private function design(string $name, ?string $collectionId = null): string
    {
        return (string) self::getContainer()->get(CreateDesignHandler::class)(new CreateDesign($name, $collectionId));
    }

    private function planCollection(PlanCollectionRelease $command): string
    {
        return (string) self::getContainer()->get(PlanCollectionReleaseHandler::class)($command);
    }

    private function planDesign(PlanDesignRelease $command): string
    {
        return (string) self::getContainer()->get(PlanDesignReleaseHandler::class)($command);
    }

    private function event(): string
    {
        return (string) self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-06')));
    }

    /**
     * @param list<array{label: string, days: int}> $steps
     */
    private function defineSettings(array $steps, int $readyDaysBefore): void
    {
        self::getContainer()->get(DefinePlanningSettingsHandler::class)(new DefinePlanningSettings($steps, $readyDaysBefore));
    }

    private function planning(): PlanningView
    {
        return self::getContainer()->get(GetPlanningHandler::class)();
    }

    /**
     * @return list<ReleaseView>
     */
    private function releases(): array
    {
        return $this->planning()->releases;
    }

    private function clear(): void
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
    }
}
