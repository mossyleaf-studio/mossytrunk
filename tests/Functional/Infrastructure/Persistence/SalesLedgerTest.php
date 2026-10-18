<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Persistence;

use App\Application\Order\ListOrders\OrderSummaries;
use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Reporting\ProductSalesLedger;
use App\Application\Reporting\SalesLedger;
use App\Application\WorkspaceContext;
use App\Domain\Discount\AppliedDiscount;
use App\Domain\Event\Event;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Order\PaymentMethod;
use App\Domain\Product\ProductRepository;
use App\Domain\Product\SellableItem;
use App\Domain\Reporting\ProductSales;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class SalesLedgerTest extends KernelTestCase
{
    use ActsAsUser;
    use CreatesProducts;

    private Event $event;
    private Ulid $print;
    private Ulid $sticker;

    protected function setUp(): void
    {
        $user = self::actAsMemberOf();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $products = self::getContainer()->get(ProductRepository::class);
        $this->print = Ulid::fromString(self::createProduct('Forêt', 1_500, 300, ['A4', 'A3']));
        $this->sticker = Ulid::fromString(self::createProduct('Mousse', 400, 0, [], (string) self::getContainer()->get(CreateProductTypeHandler::class)('Sticker')->id()));
        $this->event = Event::schedule($user->workspace(), 'Salon', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-04-30'), new \DateTimeImmutable('2026-05-01')));
        $print = $products->get($this->print);
        $sticker = $products->get($this->sticker);

        $orders = [
            Order::place('CMD-1', $this->event, self::paris('2026-04-30 18:00'), [new OrderedItem($print->sellable('A4'), 2), (new OrderedItem($sticker->sellable(null), 1))->costing(Money::zero())], [new AppliedDiscount('Lot', Money::cents(340))]),
            Order::place('CMD-2', $this->event, new \DateTimeImmutable('2026-04-30T22:30:00+00:00'), [new OrderedItem($print->sellable('A3'), 1)], []),
            Order::place('CMD-3', $this->event, self::paris('2026-05-01 10:00'), [new OrderedItem(SellableItem::unknown('Badge', Money::cents(500)), 1)], []),
            Order::place('CMD-4', $this->event, self::paris('2026-05-01 11:00'), [new OrderedItem($print->sellable('A4'), 5)], []),
        ];
        $orders[3]->refund(self::paris('2026-05-02 09:00'));
        foreach ($orders as $order) {
            $entityManager->persist($order);
        }
        $entityManager->persist($this->event);
        $entityManager->flush();
        $entityManager->clear();
    }

    public function testTotalsCountUnrefundedOrdersInTheirParisMonthAndEvent(): void
    {
        $ledger = self::getContainer()->get(SalesLedger::class);

        $months = $ledger->totalsByMonth();
        ksort($months);
        self::assertSame(['2026-04', '2026-05'], array_keys($months));
        self::assertSame([1, 3_400, 340], [$months['2026-04']->orderCount, $months['2026-04']->grossSales->amount(), $months['2026-04']->discounts->amount()]);
        self::assertSame([2, 2_000], [$months['2026-05']->orderCount, $months['2026-05']->grossSales->amount()]);

        $event = $ledger->totalsOfEvent($this->event->id());
        self::assertSame([3, 5_400, 340, 5_060, 900], [$event->orderCount, $event->grossSales->amount(), $event->discounts->amount(), $event->turnover()->amount(), $event->costOfGoods->amount()]);
        self::assertEquals($event, $ledger->totalsByEvent()[(string) $this->event->id()]);
    }

    public function testTurnoverIsSplitByPaymentMethod(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $workspace = self::getContainer()->get(WorkspaceContext::class)->current();
        foreach ([['sale-1', PaymentMethod::Cash, 700], ['sale-2', PaymentMethod::Card, 1_100], ['sale-3', PaymentMethod::Cash, 200]] as [$sale, $method, $paid]) {
            $entityManager->persist(Order::imported('CMD-'.$sale, $workspace, 'sumup', $sale, $sale, null, self::paris('2026-05-10 12:00'), [new OrderedItem(SellableItem::unknown('Montant', Money::cents($paid)), 1)], Money::cents($paid), Money::zero(), $method, [], 'Remise SumUp'));
        }
        $entityManager->flush();

        $months = self::getContainer()->get(SalesLedger::class)->turnoverByMonthAndPayment();

        self::assertSame(['none' => 3_060], array_map(static fn (Money $turnover): int => $turnover->amount(), $months['2026-04']));
        self::assertEqualsCanonicalizing(['none' => 2_000, 'cash' => 900, 'card' => 1_100], array_map(static fn (Money $turnover): int => $turnover->amount(), $months['2026-05']));
    }

    public function testProductSalesAreAfterTheLinesShareOfTheDiscounts(): void
    {
        $ledger = self::getContainer()->get(ProductSalesLedger::class);

        $atEvent = $ledger->ofEvent($this->event->id());
        usort($atEvent, static fn (ProductSales $a, ProductSales $b): int => $a->label <=> $b->label);
        self::assertSame(
            [['Badge', 1, 500, 500, true], ['Forêt — A3', 1, 1_500, 1_500, false], ['Forêt — A4', 2, 3_000, 2_700, false], ['Sticker Mousse', 1, 400, 360, true]],
            array_map(static fn (ProductSales $sales): array => [$sales->label, $sales->quantity, $sales->gross->amount(), $sales->sales->amount(), $sales->unknownCost], $atEvent),
        );

        $year = $ledger->within(DateRange::year(2026));
        usort($year, static fn (ProductSales $a, ProductSales $b): int => $a->productName <=> $b->productName);
        self::assertSame([['Forêt', null, 3, 4_200], ['Sticker Mousse', null, 1, 360]], array_map(static fn (ProductSales $sales): array => [$sales->productName, $sales->variant, $sales->quantity, $sales->sales->amount()], $year));

        self::assertSame(2, $ledger->ofProduct($this->print, DateRange::fromDates(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30')))?->quantity);
        self::assertSame(3, $ledger->ofProduct($this->print)?->quantity);
        self::assertNull($ledger->ofProduct(new Ulid()));
    }

    public function testOrderSummariesReadTheRecordedFigures(): void
    {
        $summaries = self::getContainer()->get(OrderSummaries::class)->list($this->event->id());

        self::assertSame(['CMD-4', 'CMD-3', 'CMD-2', 'CMD-1'], array_column($summaries, 'reference'));
        self::assertSame([3_400, 340, 3_060, 3, 0, 1], [$summaries[3]->subtotal, $summaries[3]->discountTotal, $summaries[3]->total, $summaries[3]->itemCount, $summaries[3]->unidentifiedLines, $summaries[3]->unknownCosts]);
        self::assertSame(3_060 - 600, $summaries[3]->profit);
        self::assertSame(['Salon', '2026-04-30T18:00:00+02:00'], [$summaries[3]->eventName, $summaries[3]->placedAt]);
        self::assertSame(1, $summaries[1]->unidentifiedLines);
        self::assertNotNull($summaries[0]->refundedAt);
        self::assertSame([], self::getContainer()->get(OrderSummaries::class)->list(new Ulid()));
    }

    private static function paris(string $localTime): \DateTimeImmutable
    {
        return BusinessTime::at($localTime);
    }
}
