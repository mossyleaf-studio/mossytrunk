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
use App\Domain\Planning\Exception\DayAndEvent;
use App\Domain\Planning\Exception\PastRestockTarget;
use App\Domain\Planning\Exception\RestockFromAnotherSupplier;
use App\Domain\Purchasing\Exception\NonPositiveLeadTime;
use App\Domain\Shared\Exception\NotFound;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

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

    public function testARestockIsPlannedOnADay(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, ' stickers holo ', new \DateTimeImmutable('2026-11-20'), null, 5));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame($restockId, $restock->id);
        self::assertSame(['id' => $this->supplierId, 'name' => 'Imprimerie du Lac'], $restock->supplier);
        self::assertSame('stickers holo', $restock->note);
        self::assertSame('2026-11-20', $restock->day);
        self::assertNull($restock->event);
        self::assertSame(5, $restock->leadDays);
        self::assertSame('2026-11-15', $restock->orderBy);
        self::assertFalse($restock->ordered);
        self::assertNull($restock->orderedOn);
        self::assertNull($restock->supplierOrder);
        self::assertSame('upcoming', $restock->state);
        self::assertFalse($restock->past);
    }

    public function testARestockOnAnEventFollowsItsReschedule(): void
    {
        $eventId = $this->event();
        $this->plan(new PlanRestock($this->supplierId, null, null, $eventId, 10));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame(['id' => $eventId, 'name' => 'Marché de Noël'], $restock->event);
        self::assertSame(['2026-12-05', '2026-11-25'], [$restock->day, $restock->orderBy]);

        self::getContainer()->get(UpdateEventHandler::class)(new UpdateEvent($eventId, 'Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-12'), new \DateTimeImmutable('2026-12-13')));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame(['2026-12-12', '2026-12-02'], [$restock->day, $restock->orderBy]);
    }

    public function testALeadTimeUnderOneDayIsRefused(): void
    {
        try {
            $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 0));
            self::fail('A lead time of 0 days was accepted.');
        } catch (NonPositiveLeadTime) {
        }
        $this->clear();

        self::assertSame([], $this->restocks());
    }

    public function testARestockCannotBeOnADayAndAnEvent(): void
    {
        $this->expectException(DayAndEvent::class);
        $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), $this->event(), 5));
    }

    public function testARestockIsRevised(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, 'stickers holo', new \DateTimeImmutable('2026-11-20'), null, 5));
        $otherSupplier = $this->supplier('Cartonnerie');
        $eventId = $this->event();

        self::getContainer()->get(ReviseRestockHandler::class)(new ReviseRestock($restockId, $otherSupplier, null, null, $eventId, 3));
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame(['id' => $otherSupplier, 'name' => 'Cartonnerie'], $restock->supplier);
        self::assertNull($restock->note);
        self::assertSame(['id' => $eventId, 'name' => 'Marché de Noël'], $restock->event);
        self::assertSame([3, '2026-12-05', '2026-12-02'], [$restock->leadDays, $restock->day, $restock->orderBy]);
    }

    public function testARestockIsCancelled(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));

        self::getContainer()->get(CancelRestockHandler::class)($restockId);
        $this->clear();

        self::assertSame([], $this->restocks());
    }

    public function testARestockIsTickedOrderedTodayThenUnticked(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
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
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $this->supplierOrder($this->supplierId));

        self::getContainer()->get(TickRestockHandler::class)($restockId, false);
        $this->clear();

        self::assertNull($this->restocks()[0]->supplierOrder);
    }

    public function testARestockIsOrderedThroughAnOrderOfItsSupplier(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
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
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
        $orderId = $this->supplierOrder($this->supplier('Cartonnerie'));

        $this->expectExceptionObject(new RestockFromAnotherSupplier('Imprimerie du Lac'));
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
    }

    public function testDeletingTheSupplierOrderKeepsTheRestockOrderedWithoutLink(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
        $orderId = $this->supplierOrder($this->supplierId);
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $orderId);
        $this->clear();

        self::getContainer()->get(DeleteSupplierOrderHandler::class)($orderId);
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame([true, '2026-11-05', null], [$restock->ordered, $restock->orderedOn, $restock->supplierOrder]);
    }

    public function testATargetDayAlreadyPastIsRefused(): void
    {
        try {
            $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-08'), null, 5));
            self::fail('A restock for yesterday was accepted.');
        } catch (PastRestockTarget) {
        }
        $this->clear();
        self::assertSame([], $this->restocks());

        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-09'), null, 5));
        $this->expectException(PastRestockTarget::class);
        self::getContainer()->get(ReviseRestockHandler::class)(new ReviseRestock($restockId, $this->supplierId, null, new \DateTimeImmutable('2026-11-08'), null, 5));
    }

    public function testRedatingTheLinkedOrderMovesTheOrderedDay(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
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
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
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
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
        $absorbedId = $this->supplierOrder($this->supplierId);
        $orderId = $this->supplierOrder($this->supplierId);
        self::getContainer()->get(OrderRestockHandler::class)($restockId, $absorbedId);
        $this->clear();

        self::getContainer()->get(MergeSupplierOrdersHandler::class)($orderId, $absorbedId);
        $this->clear();

        $restock = $this->restocks()[0];
        self::assertSame([true, $orderId], [$restock->ordered, $restock->supplierOrder['id'] ?? null]);
    }

    public function testThePlanningGroupsThePendingRestocksWithTheSteps(): void
    {
        $this->plan(new PlanRestock($this->supplierId, 'late', new \DateTimeImmutable('2026-11-12'), null, 5));
        $this->plan(new PlanRestock($this->supplierId, 'this week', new \DateTimeImmutable('2026-11-20'), null, 7));
        $this->plan(new PlanRestock($this->supplierId, 'later', new \DateTimeImmutable('2026-11-30'), null, 5));
        self::mockTime('2026-11-07 10:00');
        $this->plan(new PlanRestock($this->supplierId, 'past', new \DateTimeImmutable('2026-11-08'), null, 5));
        self::mockTime('2026-11-09 10:00');
        $ordered = $this->plan(new PlanRestock($this->supplierId, 'ordered', new \DateTimeImmutable('2026-11-18'), null, 3));
        self::getContainer()->get(TickRestockHandler::class)($ordered, true);
        $collectionId = (string) self::getContainer()->get(CreateCollectionHandler::class)('Sous-bois', null);
        self::getContainer()->get(PlanCollectionReleaseHandler::class)(new PlanCollectionRelease($collectionId, new \DateTimeImmutable('2026-11-14'), null, [['label' => 'Impression', 'days' => 3]]));
        $this->clear();

        $planning = $this->planning();

        self::assertSame([['restock', 'late', 'late', '2026-11-07']], array_map(static fn (array $item): array => [$item['kind'], $item['note'] ?? null, $item['state'], $item['orderBy'] ?? null], $planning->late));
        self::assertSame(['step', 'restock'], array_column($planning->thisWeek, 'kind'));
        self::assertSame(['Impression', 'this week'], [$planning->thisWeek[0]['label'] ?? null, $planning->thisWeek[1]['note'] ?? null]);
        self::assertSame(['later'], array_column($planning->later, 'note'));
        self::assertSame(['late', 'this week', 'ordered', 'later'], array_map(static fn (RestockView $restock): ?string => $restock->note, $planning->restocks));
        self::assertSame(['Imprimerie du Lac'], array_unique(array_map(static fn (RestockView $restock): string => $restock->supplier['name'], $planning->restocks)));
    }

    public function testRestocksOfAnotherWorkspaceAreHidden(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));

        self::actAsMemberOf('Autre atelier');

        self::assertSame([], $this->restocks());
        self::assertSame([], $this->planning()->thisWeek);
        $this->expectException(NotFound::class);
        self::getContainer()->get(TickRestockHandler::class)($restockId, true);
    }

    public function testARestockOfAnotherWorkspaceCannotBeRevisedNorCancelled(): void
    {
        $restockId = $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));

        self::actAsMemberOf('Autre atelier');
        $supplierId = $this->supplier('Cartonnerie');

        try {
            self::getContainer()->get(ReviseRestockHandler::class)(new ReviseRestock($restockId, $supplierId, null, new \DateTimeImmutable('2026-11-25'), null, 5));
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
        $this->plan(new PlanRestock($this->supplierId, null, new \DateTimeImmutable('2026-11-20'), null, 5));
    }

    public function testAnEventOfAnotherWorkspaceCannotBeUsed(): void
    {
        $eventId = $this->event();

        self::actAsMemberOf('Autre atelier');
        $supplierId = $this->supplier('Cartonnerie');

        $this->expectException(NotFound::class);
        $this->plan(new PlanRestock($supplierId, null, null, $eventId, 5));
    }

    public function testASupplierOrderOfAnotherWorkspaceCannotBeLinked(): void
    {
        $orderId = $this->supplierOrder($this->supplierId);

        self::actAsMemberOf('Autre atelier');
        $restockId = $this->plan(new PlanRestock($this->supplier('Cartonnerie'), null, new \DateTimeImmutable('2026-11-20'), null, 5));

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

    private function event(): string
    {
        return (string) self::getContainer()->get(ScheduleEventHandler::class)(new ScheduleEvent('Marché de Noël', 'Lyon', new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-06')));
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
