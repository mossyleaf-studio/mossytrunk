<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Planning;

use App\Application\Design\CreateCollection\CreateCollectionHandler;
use App\Application\Event\ScheduleEvent\ScheduleEvent;
use App\Application\Event\ScheduleEvent\ScheduleEventHandler;
use App\Application\Event\UpdateEvent\UpdateEvent;
use App\Application\Event\UpdateEvent\UpdateEventHandler;
use App\Application\Planning\CancelRestock\CancelRestockHandler;
use App\Application\Planning\GetPlanning\GetPlanningHandler;
use App\Application\Planning\GetPlanning\PlanningView;
use App\Application\Planning\OrderRestock\OrderRestockHandler;
use App\Application\Planning\PlanCollectionRelease\PlanCollectionRelease;
use App\Application\Planning\PlanCollectionRelease\PlanCollectionReleaseHandler;
use App\Application\Planning\PlanRestock\PlanRestock;
use App\Application\Planning\PlanRestock\PlanRestockHandler;
use App\Application\Planning\RestockView;
use App\Application\Planning\ReviseRestock\ReviseRestock;
use App\Application\Planning\ReviseRestock\ReviseRestockHandler;
use App\Application\Planning\TickRestock\TickRestockHandler;
use App\Application\Purchasing\CreateSupplier\CreateSupplier;
use App\Application\Purchasing\CreateSupplier\CreateSupplierHandler;
use App\Application\Purchasing\DeleteSupplierOrder\DeleteSupplierOrderHandler;
use App\Application\Purchasing\MergeSupplierOrders\MergeSupplierOrdersHandler;
use App\Application\Purchasing\PlaceSupplierOrder\PlaceSupplierOrderHandler;
use App\Application\Purchasing\PurchaseLine;
use App\Application\Purchasing\ReviseSupplierOrder\ReviseSupplierOrderHandler;
use App\Application\Purchasing\SupplierOrderDraft;
use App\Domain\Planning\Exception\PastRestockTarget;
use App\Domain\Planning\Exception\RestockFromAnotherSupplier;
use App\Domain\Planning\Exception\RestockWithoutEvent;
use App\Domain\Purchasing\Exception\NonPositiveLeadTime;
use App\Domain\Shared\Exception\NotFound;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Ulid;

final class RestockUseCasesTest extends KernelTestCase
{
    use ActsAsUser;
    use ClockSensitiveTrait;
    use CreatesProducts;

    private string $supplierId;

    protected function setUp(): void
    {
        self::actAsMemberOf();
        self::mockTime('2026-11-09 10:00');
        $this->supplierId = $this->supplier('Imprimerie du Lac');
    }

    public function testARestockIsPlannedForAnEventAndReadyOnItsReadyByDay(): void
    {
        $eventId = $this->event();
        $restockId = $this->plan(new PlanRestock($this->supplierId, ' stickers holo ', $eventId, 5));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame($restockId, $restock->id);
        self::assertSame(['id' => $this->supplierId, 'name' => 'Imprimerie du Lac'], $restock->supplier);
        self::assertSame('stickers holo', $restock->note);
        self::assertSame(['id' => $eventId, 'name' => 'Marché de Noël'], $restock->event);
        self::assertSame(['2026-12-05', '2026-12-02', '2026-11-27'], [$restock->day, $restock->readyBy, $restock->orderBy]);
        self::assertSame(5, $restock->leadDays);
        self::assertFalse($restock->ordered);
        self::assertNull($restock->orderedOn);
        self::assertNull($restock->supplierOrder);
        self::assertSame('upcoming', $restock->state);
        self::assertFalse($restock->past);
    }

    public function testARestockFollowsItsEventRescheduleAndMargin(): void
    {
        $eventId = $this->event();
        $this->plan(new PlanRestock($this->supplierId, null, $eventId, 10));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame(['2026-12-05', '2026-12-02', '2026-11-22'], [$restock->day, $restock->readyBy, $restock->orderBy]);

        self::getContainer()->get(UpdateEventHandler::class)(new UpdateEvent($eventId, 'Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-12'), new \DateTimeImmutable('2026-12-13')));
        $this->clear();
        $restock = $this->restocks()[0];
        self::assertSame(['2026-12-12', '2026-12-09', '2026-11-29'], [$restock->day, $restock->readyBy, $restock->orderBy]);

        self::getContainer()->get(UpdateEventHandler::class)(new UpdateEvent($eventId, 'Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-12'), new \DateTimeImmutable('2026-12-13'), 0));
        $this->clear();
        $restock = $this->restocks()[0];
        self::assertSame(['2026-12-12', '2026-12-12', '2026-12-02'], [$restock->day, $restock->readyBy, $restock->orderBy]);
    }

    public function testALeadTimeUnderOneDayIsRefused(): void
    {
        try {
            $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 0));
            self::fail('A lead time of 0 days was accepted.');
        } catch (NonPositiveLeadTime) {
        }
        $this->clear();

        self::assertSame([], $this->restocks());
    }

    public function testARestockNeedsAnEvent(): void
    {
        try {
            $this->plan(new PlanRestock($this->supplierId, null, null, 5));
            self::fail('A restock without event was planned.');
        } catch (RestockWithoutEvent) {
        }
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));

        $this->expectException(RestockWithoutEvent::class);
        $this->revise(new ReviseRestock($restockId, $this->supplierId, null, null, 5));
    }

    public function testARestockIsRevised(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, 'stickers holo', $this->event(), 5));
        $otherSupplier = $this->supplier('Cartonnerie');
        $eventId = $this->event('Salon d\'hiver', '2027-01-09');

        $this->revise(new ReviseRestock($restockId, $otherSupplier, null, $eventId, 3));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame(['id' => $otherSupplier, 'name' => 'Cartonnerie'], $restock->supplier);
        self::assertNull($restock->note);
        self::assertSame(['id' => $eventId, 'name' => 'Salon d\'hiver'], $restock->event);
        self::assertSame([3, '2027-01-09', '2027-01-06', '2027-01-03'], [$restock->leadDays, $restock->day, $restock->readyBy, $restock->orderBy]);
    }

    public function testARestockPlannedOnADayBeforeEventsStillShowsUntilItIsMovedOntoAnEvent(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, 'ancien', $this->event(), 5));
        $this->onPlainDay($restockId, '2026-11-20');
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertNull($restock->event);
        self::assertSame(['2026-11-20', '2026-11-20', '2026-11-15'], [$restock->day, $restock->readyBy, $restock->orderBy]);

        $eventId = $this->event('Salon d\'hiver', '2027-01-09');
        $this->revise(new ReviseRestock($restockId, $this->supplierId, 'ancien', $eventId, 5));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame(['id' => $eventId, 'name' => 'Salon d\'hiver'], $restock->event);
        self::assertSame(['2027-01-09', '2027-01-06'], [$restock->day, $restock->readyBy]);
    }

    public function testARestockIsCancelled(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));

        self::getContainer()->get(CancelRestockHandler::class)($restockId);
        $this->clear();

        self::assertSame([], $this->restocks());
    }

    public function testARestockIsTickedOrderedTodayThenUnticked(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        $tick = self::getContainer()->get(TickRestockHandler::class);

        $tick($restockId, true);
        $this->clear();
        $restock = $this->restocks()[0];
        self::assertSame([true, '2026-11-09', 'done'], [$restock->ordered, $restock->orderedOn, $restock->state]);

        $tick($restockId, false);
        $this->clear();
        $restock = $this->restocks()[0];
        self::assertSame([false, null, 'upcoming'], [$restock->ordered, $restock->orderedOn, $restock->state]);
    }

    public function testUntickingARestockOrderedThroughASupplierOrderDropsTheLink(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $this->supplierOrder($this->supplierId));

        self::getContainer()->get(TickRestockHandler::class)($restockId, false);
        $this->clear();

        self::assertNull($this->restocks()[0]->supplierOrder);
    }

    public function testARestockIsOrderedThroughAnOrderOfItsSupplier(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        $orderId = $this->supplierOrder($this->supplierId);

        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame([true, '2026-11-05', 'done'], [$restock->ordered, $restock->orderedOn, $restock->state]);
        self::assertNotNull($restock->supplierOrder);
        self::assertSame($orderId, $restock->supplierOrder['id']);
        self::assertNotSame('', $restock->supplierOrder['reference']);
    }

    public function testAnOrderOfAnotherSupplierIsRefused(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        $orderId = $this->supplierOrder($this->supplier('Cartonnerie'));

        $this->expectExceptionObject(new RestockFromAnotherSupplier('Imprimerie du Lac'));
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
    }

    public function testDeletingTheSupplierOrderKeepsTheRestockOrderedWithoutLink(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        $orderId = $this->supplierOrder($this->supplierId);
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
        $this->clear();

        self::getContainer()->get(DeleteSupplierOrderHandler::class)($orderId);
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame([true, '2026-11-05', null], [$restock->ordered, $restock->orderedOn, $restock->supplierOrder]);
    }

    public function testAnEventAlreadyPastIsRefused(): void
    {
        $past = $this->event('Salon passé', '2026-11-07', '2026-11-08');
        try {
            $this->plan(new PlanRestock($this->supplierId, null, $past, 5));
            self::fail('A restock for a past event was accepted.');
        } catch (PastRestockTarget) {
        }
        $this->clear();
        self::assertSame([], $this->restocks());

        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event('Salon du jour', '2026-11-09'), 5));
        $this->expectException(PastRestockTarget::class);
        $this->revise(new ReviseRestock($restockId, $this->supplierId, null, $past, 5));
    }

    public function testRedatingTheLinkedOrderMovesTheOrderedDay(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        $orderId = $this->supplierOrder($this->supplierId);
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
        $this->clear();

        $this->reviseOrder($orderId, $this->supplierId, '2026-11-03');
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame(['2026-11-03', $orderId], [$restock->orderedOn, $restock->supplierOrder['id'] ?? null]);
    }

    public function testMovingTheLinkedOrderToAnotherSupplierDropsTheLinkAndKeepsItOrdered(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        $orderId = $this->supplierOrder($this->supplierId);
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
        $this->clear();

        $this->reviseOrder($orderId, $this->supplier('Cartonnerie'), '2026-11-04');
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame([true, '2026-11-04', null], [$restock->ordered, $restock->orderedOn, $restock->supplierOrder]);
    }

    public function testMergingTheLinkedOrderLinksTheRestockToTheOrderThatAbsorbsIt(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
        $absorbedId = $this->supplierOrder($this->supplierId);
        $orderId = $this->supplierOrder($this->supplierId);
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $absorbedId);
        $this->clear();

        self::getContainer()->get(MergeSupplierOrdersHandler::class)($orderId, $absorbedId);
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame([true, $orderId], [$restock->ordered, $restock->supplierOrder['id'] ?? null]);
    }

    public function testThePlanningGroupsTheRestocksWithTheStepsAndKeepsTheOrderedOnesUntilTheirOrderByDay(): void
    {
        $this->plan(new PlanRestock($this->supplierId, 'late', $this->event('Salon du 12', '2026-11-12'), 5));
        $twentieth = $this->event('Salon du 20', '2026-11-20');
        $this->plan(new PlanRestock($this->supplierId, 'this week', $twentieth, 4));
        $this->plan(new PlanRestock($this->supplierId, 'later', $this->event('Salon du 30', '2026-11-30'), 5));
        self::mockTime('2026-11-07 10:00');
        $this->plan(new PlanRestock($this->supplierId, 'past', $this->event('Salon du 8', '2026-11-08'), 5));
        self::mockTime('2026-11-09 10:00');
        $ordered = $this->plan(new PlanRestock($this->supplierId, 'ordered', $twentieth, 3));
        self::getContainer()->get(TickRestockHandler::class)($ordered, true);
        $orderedLate = $this->plan(new PlanRestock($this->supplierId, 'ordered late', $this->event('Salon du 11', '2026-11-11'), 6));
        self::getContainer()->get(TickRestockHandler::class)($orderedLate, true);
        $collectionId = (string) self::getContainer()->get(CreateCollectionHandler::class)('Sous-bois', null);
        self::getContainer()->get(PlanCollectionReleaseHandler::class)(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-11-14'), null, [['label' => 'Impression', 'days' => 3]]));
        $this->clear();

        $planning = $this->planning();

        self::assertSame([['restock', 'late', 'late', '2026-11-04']], array_map(static fn (array $item): array => [$item['kind'], $item['note'] ?? null, $item['state'], $item['orderBy'] ?? null], $planning->late));
        self::assertSame(
            [['step', 'Impression', 'upcoming'], ['restock', 'this week', 'upcoming'], ['restock', 'ordered', 'done']],
            array_map(static fn (array $item): array => [$item['kind'], 'step' === $item['kind'] ? $item['label'] : $item['note'], $item['state']], $planning->thisWeek),
        );
        self::assertSame(['later'], array_column($planning->later, 'note'));
        self::assertSame(['ordered late', 'late', 'this week', 'ordered', 'later'], array_map(static fn (RestockView $restock): ?string => $restock->note, $planning->restocks));
        self::assertSame(['Imprimerie du Lac'], array_unique(array_map(static fn (RestockView $restock): string => $restock->supplier['name'], $planning->restocks)));
    }

    public function testRestocksOfAnotherWorkspaceAreHidden(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));

        self::actAsMemberOf('Autre atelier');

        self::assertSame([], $this->restocks());
        self::assertSame([], $this->planning()->thisWeek);
        $this->expectException(NotFound::class);
        self::getContainer()->get(TickRestockHandler::class)($restockId, true);
    }

    public function testARestockOfAnotherWorkspaceCannotBeRevisedNorCancelled(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));

        self::actAsMemberOf('Autre atelier');
        $supplierId = $this->supplier('Cartonnerie');

        try {
            self::getContainer()->get(ReviseRestockHandler::class)(new ReviseRestock($restockId, $supplierId, null, $this->event('Salon', '2026-12-20'), 5));
            self::fail('A restock of another workspace was revised.');
        } catch (NotFound) {
        }
        $this->expectException(NotFound::class);
        self::getContainer()->get(CancelRestockHandler::class)($restockId);
    }

    public function testASupplierOfAnotherWorkspaceCannotBeUsed(): void
    {
        self::actAsMemberOf('Autre atelier');

        $this->expectException(NotFound::class);
        $this->plan(new PlanRestock($this->supplierId, null, $this->event(), 5));
    }

    public function testAnEventOfAnotherWorkspaceCannotBeUsed(): void
    {
        $eventId = $this->event();

        self::actAsMemberOf('Autre atelier');
        $supplierId = $this->supplier('Cartonnerie');

        $this->expectException(NotFound::class);
        $this->plan(new PlanRestock($supplierId, null, $eventId, 5));
    }

    public function testASupplierOrderOfAnotherWorkspaceCannotBeLinked(): void
    {
        $orderId = $this->supplierOrder($this->supplierId);

        self::actAsMemberOf('Autre atelier');
        $restockId = $this->plan(new PlanRestock($this->supplier('Cartonnerie'), null, $this->event(), 5));

        $this->expectException(NotFound::class);
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
    }

    private function supplier(string $name): string
    {
        return (string) self::getContainer()->get(CreateSupplierHandler::class)(new CreateSupplier($name))->id();
    }

    private function supplierOrder(string $supplierId): string
    {
        $product = self::createProduct('Sticker '.uniqid(), 400, 30);

        return (string) self::getContainer()->get(PlaceSupplierOrderHandler::class)(new SupplierOrderDraft($supplierId, new \DateTimeImmutable('2026-11-05'), [new PurchaseLine($product, null, 100, 2_000)]));
    }

    private function reviseOrder(string $orderId, string $supplierId, string $orderedOn): void
    {
        $product = self::createProduct('Badge '.$orderId, 300, 10);
        self::getContainer()->get(ReviseSupplierOrderHandler::class)($orderId, new SupplierOrderDraft($supplierId, new \DateTimeImmutable($orderedOn), [new PurchaseLine($product, null, 50, 1_000)]));
    }

    private function event(string $name = 'Marché de Noël', string $start = '2026-12-05', ?string $end = null): string
    {
        return (string) self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent($name, 'Lyon', new \DateTimeImmutable($start), new \DateTimeImmutable($end ?? $start)));
    }

    private function onPlainDay(string $restockId, string $day): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE restock SET day = ?, event_id = NULL WHERE id = ?', [$day, Ulid::fromString($restockId)->toRfc4122()]);
    }

    private function revise(ReviseRestock $command): void
    {
        self::getContainer()->get(ReviseRestockHandler::class)($command);
    }

    private function plan(PlanRestock $command): string
    {
        return (string) self::getContainer()->get(PlanRestockHandler::class)($command);
    }

    private function planning(): PlanningView
    {
        return self::getContainer()->get(GetPlanningHandler::class)();
    }

    /**
     * @return list<RestockView>
     */
    private function restocks(): array
    {
        return $this->planning()->restocks;
    }

    private function clear(): void
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
    }
}
