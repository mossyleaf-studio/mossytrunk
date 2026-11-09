<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Planning;

use App\Application\Design\CreateCollection\CreateCollectionHandler;
use App\Application\Design\CreateDesign\CreateDesign;
use App\Application\Design\CreateDesign\CreateDesignHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Planning\GetEventPreparation\GetEventPreparationHandler;
use App\Application\Planning\PlanCollectionRelease\PlanCollectionRelease;
use App\Application\Planning\PlanCollectionRelease\PlanCollectionReleaseHandler;
use App\Application\Planning\PlanDesignRelease\PlanDesignRelease;
use App\Application\Planning\PlanDesignRelease\PlanDesignReleaseHandler;
use App\Application\Planning\PlanRestock\PlanRestock;
use App\Application\Planning\PlanRestock\PlanRestockHandler;
use App\Application\Planning\ReleaseView;
use App\Application\Planning\RestockView;
use App\Application\Purchasing\CreateSupplier\CreateSupplier;
use App\Application\Purchasing\CreateSupplier\CreateSupplierHandler;
use App\Domain\Shared\Exception\NotFound;
use App\Tests\Support\ActsAsUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class EventPreparationTest extends KernelTestCase
{
    use ActsAsUser;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        self::mockTime('2026-11-09 10:00');
    }

    public function testAnEventPreparationGathersOnlyItsReleasesAndRestocks(): void
    {
        $christmas = $this->event('Marché de Noël', '2026-12-05', '2026-12-06', 4);
        $winter = $this->event('Salon d\'hiver', '2027-01-09', '2027-01-10');
        $woods = $this->collection('Sous-bois');
        $this->planCollection(new PlanCollectionRelease($woods, null, $christmas, [['label' => 'Impression', 'days' => 3]]));
        $this->planDesign(new PlanDesignRelease($this->design('Héron'), null, $winter, []));
        $this->planDesign(new PlanDesignRelease($this->design('Fougère'), new \DateTimeImmutable('2026-12-05'), null, []));
        $printer = $this->supplier('Imprimerie du Lac');
        $this->plan(new PlanRestock($printer, 'stickers', $christmas, 2));
        $this->plan(new PlanRestock($printer, 'tirages', $christmas, 10));
        $this->plan(new PlanRestock($printer, 'cartes', $winter, 5));
        self::getContainer()->get('doctrine')->getManager()->clear();

        $preparation = self::getContainer()->get(GetEventPreparationHandler::class)($christmas);

        self::assertSame(['id' => $christmas, 'name' => 'Marché de Noël', 'start' => '2026-12-05', 'end' => '2026-12-06'], $preparation->event);
        self::assertSame(['2026-12-01', 4], [$preparation->readyBy, $preparation->readyDaysBefore]);
        self::assertSame([['kind' => 'collection', 'id' => $woods, 'name' => 'Sous-bois']], array_map(static fn (ReleaseView $release): array => $release->owner, $preparation->releases));
        self::assertSame(['2026-11-29', '2026-12-01'], [$preparation->releases[0]->steps[0]['start'], $preparation->releases[0]->steps[0]['end']]);
        self::assertSame([['tirages', '2026-11-21'], ['stickers', '2026-11-29']], array_map(static fn (RestockView $restock): array => [$restock->note, $restock->orderBy], $preparation->restocks));
        self::assertSame(['2026-12-01'], array_unique(array_map(static fn (RestockView $restock): string => $restock->readyBy, $preparation->restocks)));
    }

    public function testAnEventWithoutOwnMarginIsReadyTheWorkspaceMarginBefore(): void
    {
        $preparation = self::getContainer()->get(GetEventPreparationHandler::class)($this->event('Salon', '2026-12-05', '2026-12-05'));

        self::assertSame(['2026-12-02', 3, [], []], [$preparation->readyBy, $preparation->readyDaysBefore, $preparation->releases, $preparation->restocks]);
    }

    public function testTheEventOfAnotherWorkspaceIsNotFound(): void
    {
        $eventId = $this->event('Salon', '2026-12-05', '2026-12-05');

        self::actAsMemberOf('Autre atelier');

        $this->expectException(NotFound::class);
        self::getContainer()->get(GetEventPreparationHandler::class)($eventId);
    }

    private function event(string $name, string $start, string $end, ?int $readyDaysBefore = null): string
    {
        return (string) self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent($name, 'Lyon', new \DateTimeImmutable($start), new \DateTimeImmutable($end), $readyDaysBefore));
    }

    private function collection(string $name): string
    {
        return (string) self::getContainer()->get(CreateCollectionHandler::class)($name, null);
    }

    private function design(string $name): string
    {
        return (string) self::getContainer()->get(CreateDesignHandler::class)(new CreateDesign($name, null));
    }

    private function planCollection(PlanCollectionRelease $command): void
    {
        self::getContainer()->get(PlanCollectionReleaseHandler::class)($command);
    }

    private function planDesign(PlanDesignRelease $command): void
    {
        self::getContainer()->get(PlanDesignReleaseHandler::class)($command);
    }

    private function supplier(string $name): string
    {
        return (string) self::getContainer()->get(CreateSupplierHandler::class)(new CreateSupplier($name))->id();
    }

    private function plan(PlanRestock $command): void
    {
        self::getContainer()->get(PlanRestockHandler::class)($command);
    }
}
