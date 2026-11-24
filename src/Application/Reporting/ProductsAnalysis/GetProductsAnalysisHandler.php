<?php

declare(strict_types=1);

namespace App\Application\Reporting\ProductsAnalysis;

use App\Application\Reporting\ProductSalesLedger;
use App\Application\Reporting\ReportPeriod;
use App\Application\Reporting\SalesCalendar;
use App\Application\Reporting\SoldFigures;
use App\Domain\Product\ProductRepository;
use App\Domain\Reporting\Analysis\Arrival;
use App\Domain\Reporting\Analysis\Exposure;
use App\Domain\Reporting\Analysis\MarginClass;
use App\Domain\Reporting\Analysis\OrderCalendar;
use App\Domain\Reporting\Analysis\Reason;
use App\Domain\Reporting\Analysis\SoldEvent;
use App\Domain\Reporting\Analysis\StockLevel;
use App\Domain\Reporting\Analysis\TypeBenchmark;
use App\Domain\Reporting\Analysis\UnitsByEvent;
use App\Domain\Reporting\ProductSales;
use App\Domain\Reporting\StockPotential;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use App\Domain\Stock\StockRepository;
use Psr\Clock\ClockInterface;

final readonly class GetProductsAnalysisHandler
{
    public function __construct(
        private ProductSalesLedger $sales,
        private SalesCalendar $calendar,
        private ProductRepository $products,
        private StockRepository $stock,
        private ReportPeriod $periods,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $choice): ProductsAnalysisView
    {
        $range = $this->periods->resolve($choice);
        $current = !$range->isBefore($this->clock->now());
        $calendar = OrderCalendar::of($this->calendar->days($range));
        $units = UnitsByEvent::of($this->calendar->unitsByEvent($range));
        $analysed = $this->analysed($range, $current, $calendar, $units);
        $benchmarks = self::benchmarks($analysed);

        $rows = [];
        foreach (MarginClass::ranking(array_map(static fn (AnalysedProduct $product): array => ['name' => $product->product->displayName(), 'margin' => $product->margin()], $analysed)) as $key => $rank) {
            $rows[] = self::row($analysed[$key], $rank, $benchmarks[$analysed[$key]->typeKey()], $calendar, $units, $current);
        }
        $busiest = $calendar->busiestEvent();

        return new ProductsAnalysisView(
            [
                'enoughOrders' => Exposure::ENOUGH_ORDERS,
                'popularPercent' => TypeBenchmark::POPULAR_PERCENT,
                'profitablePercent' => TypeBenchmark::PROFITABLE_PERCENT,
                'classAPercent' => MarginClass::A_UNDER_PERCENT,
                'classBPercent' => MarginClass::B_UNDER_PERCENT,
            ],
            ['choice' => $choice, 'from' => $range->start()->format('Y-m-d'), 'to' => $range->end()->format('Y-m-d'), 'current' => $current],
            $calendar->orders(),
            $calendar->offEventOrders(),
            array_map(static fn (SoldEvent $event): array => self::event($event, $calendar), $calendar->events()),
            null === $busiest ? null : self::event($busiest, $calendar),
            self::types($analysed, $benchmarks),
            $rows,
            [
                'units' => array_sum(array_column($rows, 'units')),
                'discountedUnits' => array_sum(array_column($rows, 'discountedUnits')),
                'revenue' => array_sum(array_column($rows, 'revenue')),
                'margin' => array_sum(array_column($rows, 'margin')),
                'onHand' => array_sum(array_column($rows, 'onHand')),
            ],
        );
    }

    /**
     * @return array<string, AnalysedProduct>
     */
    private function analysed(DateRange $range, bool $current, OrderCalendar $calendar, UnitsByEvent $units): array
    {
        $sold = $this->soldWithin($range);
        $stock = $this->stock->byProduct();

        $analysed = [];
        foreach ($this->products->articles() as $product) {
            $key = (string) $product->id();
            $arrival = Arrival::of($product, $stock[$key] ?? [], $units->firstSale($product->id()));
            if (null === $arrival || !$arrival->isBy($range->end()->format('Y-m-d'))) {
                continue;
            }
            $onHand = StockPotential::of($product, $stock[$key] ?? [])->units;
            $soldOutAfter = $current && 0 === $onHand ? $units->lastSale($product->id()) ?? self::dayBefore($range) : null;
            $analysed[$key] = new AnalysedProduct($product, Exposure::of($calendar, $arrival->day, $sold[$key]->quantity ?? 0, $soldOutAfter), $sold[$key] ?? null, $onHand);
        }

        return $analysed;
    }

    private static function dayBefore(DateRange $range): string
    {
        return $range->start()->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @return array<string, ProductSales>
     */
    private function soldWithin(DateRange $range): array
    {
        $sold = [];
        foreach ($this->sales->within($range) as $sales) {
            $sold[(string) $sales->productId] = $sales;
        }

        return $sold;
    }

    /**
     * @param array<string, AnalysedProduct> $analysed
     *
     * @return array<string, TypeBenchmark>
     */
    private static function benchmarks(array $analysed): array
    {
        $benchmarks = [];
        foreach ($analysed as $product) {
            $type = $product->typeKey();
            $benchmarks[$type] = ($benchmarks[$type] ?? TypeBenchmark::none())->with($product->exposure, $product->margin());
        }

        return $benchmarks;
    }

    /**
     * @param array{class: MarginClass, cumulative: Money} $rank
     *
     * @return array{id: string, name: string, typeId: string, typeName: string, units: int, fullPriceUnits: int, discountedUnits: int, gross: int, discountedGross: int, discount: int, revenue: int, cost: int, margin: int, unknownCost: bool, unitMargin: int|null, availableSince: string, exposure: int, ordersSinceArrival: int, per100Orders: float|null, class: string, quadrant: string|null, cumulativeMargin: int, onHand: int, coverOrders: int|null, stock: string|null, byEvent: list<int|null>, offEvent: int, reason: string|null, verdict: string|null}
     */
    private static function row(AnalysedProduct $analysed, array $rank, TypeBenchmark $benchmark, OrderCalendar $calendar, UnitsByEvent $units, bool $current): array
    {
        $product = $analysed->product;
        $exposure = $analysed->exposure;
        $quadrant = $benchmark->quadrant($exposure, $analysed->margin());
        $cover = $current ? $exposure->coverOrders($analysed->onHand) : null;
        $stock = $current ? StockLevel::of($analysed->onHand, $cover, $calendar->busiestEventOrders()) : null;
        $reason = null === $stock ? null : Reason::of($exposure, $rank['class'], $quadrant, $stock);

        return [
            'id' => (string) $product->id(),
            'name' => $product->displayName(),
            'typeId' => $analysed->typeKey(),
            'typeName' => $product->type()->name(),
            ...SoldFigures::of($analysed->sold),
            'unitMargin' => TypeBenchmark::unitMargin($analysed->margin(), $exposure->units)?->amount(),
            'availableSince' => $exposure->arrival,
            'exposure' => $exposure->orders,
            'ordersSinceArrival' => $exposure->sinceArrival,
            'per100Orders' => $exposure->per100Orders(),
            'class' => $rank['class']->value,
            'quadrant' => $quadrant?->value,
            'cumulativeMargin' => $rank['cumulative']->amount(),
            'onHand' => $analysed->onHand,
            'coverOrders' => $cover,
            'stock' => $stock?->value,
            'byEvent' => $units->row($product->id(), $calendar->events(), $exposure),
            'offEvent' => $units->offEvent($product->id()),
            'reason' => $reason?->value,
            'verdict' => $reason?->verdict()->value,
        ];
    }

    /**
     * @return array{id: string, name: string, from: string, to: string, orders: int}
     */
    private static function event(SoldEvent $event, OrderCalendar $calendar): array
    {
        return ['id' => (string) $event->id, 'name' => $event->name, 'from' => $event->from, 'to' => $event->to, 'orders' => $calendar->ordersAt($event)];
    }

    /**
     * @param array<string, AnalysedProduct> $analysed
     * @param array<string, TypeBenchmark>   $benchmarks
     *
     * @return list<array{id: string, name: string, judged: int, averagePace: float|null, popularFrom: float|null, averageUnitMargin: int|null, unitMarginFrom: int|null}>
     */
    private static function types(array $analysed, array $benchmarks): array
    {
        $types = [];
        foreach ($analysed as $product) {
            $benchmark = $benchmarks[$product->typeKey()];
            $types[$product->typeKey()] = ['id' => $product->typeKey(), 'name' => $product->product->type()->name(), 'judged' => $benchmark->judged, 'averagePace' => $benchmark->averagePace(), 'popularFrom' => $benchmark->popularFrom(), 'averageUnitMargin' => $benchmark->averageUnitMargin()?->amount(), 'unitMarginFrom' => $benchmark->unitMarginFrom()?->amount()];
        }

        return array_values($types);
    }
}
