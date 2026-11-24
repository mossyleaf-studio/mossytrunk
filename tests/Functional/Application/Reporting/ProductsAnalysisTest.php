<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Reporting;

use App\Application\Product\CreateProductType\CreateProductTypeHandler;
use App\Application\Reporting\ProductsAnalysis\GetProductsAnalysisHandler;
use App\Domain\Event\Event;
use App\Domain\Order\Order;
use App\Domain\Order\OrderedItem;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Sales\SalesChannel;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use App\Domain\Stock\LotOrigin;
use App\Domain\Stock\StockItem;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\CreatesProducts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Ulid;

final class ProductsAnalysisTest extends KernelTestCase
{
    use ActsAsUser;
    use ClockSensitiveTrait;
    use CreatesProducts;

    private const array STOCK = ['mousse' => [20, '2026-07-01'], 'print' => [24, '2026-07-01'], 'holo' => [40, '2026-07-01'], 'lichen' => [0, '2026-07-01'], 'fougere' => [42, '2026-07-01'], 'nouveau' => [25, '2026-09-20'], 'dormant' => [20, '2026-07-01']];

    /** @var array<string, Product> */
    private array $products = [];
    private int $reference = 0;

    protected function setUp(): void
    {
        $workspace = self::actAsMemberOf()->workspace();
        $container = self::getContainer();
        $stickers = (string) $container->get(CreateProductTypeHandler::class)('Sticker')->id();
        $prints = (string) $container->get(CreateProductTypeHandler::class)('Print')->id();
        $ids = [
            'mousse' => self::createProduct('Mousse', 300, 50, typeId: $stickers),
            'print' => self::createProduct('Forêt', 1_000, 200, typeId: $prints),
            'holo' => self::createProduct('Holo', 300, 150, typeId: $stickers),
            'lichen' => self::createProduct('Lichen', 300, 50, typeId: $stickers),
            'fougere' => self::createProduct('Fougère', 300, 50, typeId: $stickers),
            'nouveau' => self::createProduct('Nouveau', 300, 50, typeId: $stickers),
            'dormant' => self::createProduct('Dormant', 300, 50, typeId: $stickers),
            'archived' => self::createProduct('Ancien', 300, 50, typeId: $stickers),
        ];
        self::createProduct('Fantôme', 300, 50, typeId: $stickers);
        $repository = $container->get(ProductRepository::class);
        $this->products = array_map(static fn (string $id): Product => $repository->get(Ulid::fromString($id)), $ids);
        $this->products['archived']->archive(BusinessTime::at('2026-11-01'));

        $sold = ['mousse' => 40, 'print' => 6, 'holo' => 20, 'lichen' => 12, 'fougere' => 8, 'nouveau' => 5, 'dormant' => 0];
        $entityManager = $container->get(EntityManagerInterface::class);
        foreach (self::STOCK as $key => [$onHand, $day]) {
            $item = StockItem::open($this->products[$key], null);
            $item->receive($onHand + $sold[$key], Money::cents(50 * ($onHand + $sold[$key])), LotOrigin::Purchase, BusinessTime::at("$day 10:00"));
            if ($sold[$key] > 0) {
                $item->withdraw($sold[$key], Money::cents(50));
            }
            $entityManager->persist($item);
        }

        $expo = Event::schedule($workspace, 'Expo', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-07-09'), new \DateTimeImmutable('2026-07-10')));
        $market = Event::schedule($workspace, 'Marché', 'Lyon', DateRange::fromDates(new \DateTimeImmutable('2026-09-26'), new \DateTimeImmutable('2026-09-26')));
        $entityManager->persist($expo);
        $entityManager->persist($market);
        $this->sell('2026-07-09', $expo, 100, ['mousse' => 30, 'print' => 5, 'holo' => 16, 'lichen' => 12, 'fougere' => 6]);
        $this->sell('2026-07-10', $expo, 60, []);
        $this->sell('2026-09-26', $market, 40, ['mousse' => 8, 'print' => 1, 'holo' => 4, 'fougere' => 2, 'nouveau' => 5]);
        $shop = SalesChannel::open($workspace, 'Boutique');
        $entityManager->persist($shop);
        $this->sell('2026-10-05', $shop, 4, ['mousse' => 2]);
        $entityManager->flush();
        $entityManager->clear();
    }

    public function testEachProductOfTheYearGetsItsClassQuadrantStockAndVerdict(): void
    {
        self::mockTime('2026-11-24 12:00');

        $analysis = self::getContainer()->get(GetProductsAnalysisHandler::class)('2026');

        self::assertSame(['choice' => '2026', 'from' => '2026-01-01', 'to' => '2026-12-31', 'current' => true], $analysis->period);
        self::assertSame([204, 4], [$analysis->orders, $analysis->offEventOrders]);
        self::assertSame([['Expo', '2026-07-09', '2026-07-10', 160], ['Marché', '2026-09-26', '2026-09-26', 40]], array_map(static fn (array $event): array => [$event['name'], $event['from'], $event['to'], $event['orders']], $analysis->events));
        self::assertSame($analysis->events[0], $analysis->busiestEvent);
        self::assertSame([['Print', 1, 2.0, 680], ['Sticker', 5, 6.3, 191]], array_map(static fn (array $type): array => [$type['name'], $type['judged'], $type['popularFrom'], $type['unitMarginFrom']], $analysis->types));
        self::assertSame(
            [
                ['Sticker Mousse', 40, 10_000, 250, '2026-07-01', 204, 19.6, 'A', 'star', 10_000, 20, 102, 'low', [30, 8], 2, 'restock'],
                ['Print Forêt', 6, 4_800, 800, '2026-07-01', 204, 2.9, 'A', 'star', 14_800, 24, 816, 'ok', [5, 1], 0, 'keep'],
                ['Sticker Holo', 20, 3_000, 150, '2026-07-01', 204, 9.8, 'A', 'workhorse', 17_800, 40, 408, 'ok', [16, 4], 0, 'reprice'],
                ['Sticker Lichen', 12, 3_000, 250, '2026-07-01', 100, 12.0, 'A', 'star', 20_800, 0, 0, 'out', [12, null], 0, 'restock'],
                ['Sticker Fougère', 8, 2_000, 250, '2026-07-01', 204, 3.9, 'B', 'puzzle', 22_800, 42, 1_071, 'ok', [6, 2], 0, 'watch'],
                ['Sticker Nouveau', 5, 1_250, 250, '2026-09-20', 44, 11.4, 'B', null, 24_050, 25, 220, 'ok', [null, 5], 0, 'too_early'],
                ['Sticker Dormant', 0, 0, null, '2026-07-01', 204, 0.0, 'C', null, 24_050, 20, null, 'ok', [0, 0], 0, 'discontinue'],
            ],
            array_map(static fn (array $row): array => [$row['name'], $row['units'], $row['margin'], $row['unitMargin'], $row['availableSince'], $row['exposure'], $row['per100Orders'], $row['class'], $row['quadrant'], $row['cumulativeMargin'], $row['onHand'], $row['coverOrders'], $row['stock'], $row['byEvent'], $row['offEvent'], $row['verdict']], $analysis->products),
        );
        self::assertSame(['units' => 91, 'revenue' => 31_500, 'margin' => 24_050, 'onHand' => 171], $analysis->totals);
    }

    public function testAPeriodThatIsOverIsJudgedWithoutTodaysStock(): void
    {
        self::mockTime('2027-02-01 12:00');

        $analysis = self::getContainer()->get(GetProductsAnalysisHandler::class)('2026');

        self::assertFalse($analysis->period['current']);
        self::assertSame([[null, null, null]], array_values(array_unique(array_map(static fn (array $row): array => [$row['stock'], $row['coverOrders'], $row['verdict']], $analysis->products), \SORT_REGULAR)));
        $lichen = array_values(array_filter($analysis->products, static fn (array $row): bool => 'Sticker Lichen' === $row['name']))[0];
        self::assertSame([204, 5.9, [12, 0]], [$lichen['exposure'], $lichen['per100Orders'], $lichen['byEvent']]);
    }

    public function testAProductSoldOutBeforeThePeriodIsNotJudgedOnOrdersItCouldNotTake(): void
    {
        self::mockTime('2027-08-15 12:00');

        $analysis = self::getContainer()->get(GetProductsAnalysisHandler::class)('12m');

        self::assertSame([44, ['Marché']], [$analysis->orders, array_column($analysis->events, 'name')]);
        $lichen = array_values(array_filter($analysis->products, static fn (array $row): bool => 'Sticker Lichen' === $row['name']))[0];
        self::assertSame([0, null, [null], 'out'], [$lichen['exposure'], $lichen['per100Orders'], $lichen['byEvent'], $lichen['stock']]);
    }

    /**
     * @param array<string, int> $units
     */
    private function sell(string $day, Event|SalesChannel $where, int $orders, array $units): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $lines = [];
        foreach ($units as $key => $count) {
            $lines = [...$lines, ...array_fill(0, $count, $key)];
        }
        for ($i = 0; $i < $orders; ++$i) {
            $items = [new OrderedItem($this->products['archived']->sellable(null), 1)];
            if (isset($lines[$i])) {
                $items[] = new OrderedItem($this->products[$lines[$i]]->sellable(null), 1);
            }
            $reference = 'CMD-'.++$this->reference;
            $placedAt = BusinessTime::at("$day 12:00");
            $entityManager->persist($where instanceof Event ? Order::place($reference, $where, $placedAt, $items, []) : Order::placeOn($reference, $where, $placedAt, $items, []));
        }
    }
}
