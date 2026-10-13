<?php

declare(strict_types=1);

namespace App\Application\Dashboard\GetDashboard;

use App\Application\Reporting\ProductSalesLedger;
use App\Application\Reporting\SalesLedger;
use App\Application\Stock\ConsumedSupplies;
use App\Application\Stock\ProductStock;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Event\ExpenseShares;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Reporting\MonthlyResults;
use App\Domain\Reporting\ProductSales;
use App\Domain\Reporting\SalesByProduct;
use App\Domain\Reporting\SalesFigures;
use App\Domain\Reporting\SalesTotals;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Money;
use App\Domain\Stock\StockRepository;
use Psr\Clock\ClockInterface;

final readonly class GetDashboardHandler
{
    private const int TOP_PRODUCTS = 5;

    public function __construct(
        private SalesLedger $sales,
        private ProductSalesLedger $productSales,
        private EventRepository $events,
        private ProductRepository $products,
        private StockRepository $stock,
        private ConsumedSupplies $consumedSupplies,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?int $year = null): DashboardView
    {
        $events = $this->events->all();
        $consumed = $this->consumedSupplies->byEvent();
        $shares = ExpenseShares::among($events);
        $results = MonthlyResults::of($this->sales->totalsByMonth(), $events, $shares, $consumed);
        $year ??= DateRange::yearOf($this->clock->now());

        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $months[] = ['month' => $month] + $results->month($year, $month)->toArray();
        }

        $sales = SalesByProduct::of($this->productSales->within(DateRange::year($year)))->ranked();
        $products = $this->products->all();
        $typeNames = $this->typeNamesOf($products);
        $stocks = $this->stocksOf($products);

        return new DashboardView(
            $year,
            $results->years($year),
            $months,
            $results->year($year)->toArray(),
            array_map(static fn (int $y): array => ['year' => $y] + $results->year($y)->toArray(), $results->years()),
            $this->eventsOf($year, $events, $shares, $consumed),
            array_map(static fn (ProductSales $product): array => [
                'id' => (string) $product->productId,
                'name' => $product->productName,
                'typeName' => $typeNames[(string) $product->productId] ?? null,
                'quantity' => $product->quantity,
                'sales' => $product->sales->amount(),
            ], \array_slice($sales, 0, self::TOP_PRODUCTS)),
            $this->salesByType($sales, $typeNames),
            \count(array_filter($products, static fn (Product $product): bool => $product->buyingPrice()->isZero())),
            \count(array_filter($stocks, static fn (ProductStock $stock): bool => $stock->low && !$stock->negative)),
            \count(array_filter($stocks, static fn (ProductStock $stock): bool => $stock->negative)),
        );
    }

    /**
     * @param list<Product> $products
     *
     * @return list<ProductStock>
     */
    private function stocksOf(array $products): array
    {
        $byProduct = $this->stock->byProduct();

        return array_map(static fn (Product $product): ProductStock => ProductStock::of($product, $byProduct[(string) $product->id()] ?? []), $products);
    }

    /**
     * @param list<Event>          $events
     * @param array<string, Money> $consumed
     *
     * @return list<array{id: string, name: string, startDate: string, turnover: int, result: int}>
     */
    private function eventsOf(int $year, array $events, ExpenseShares $shares, array $consumed): array
    {
        $sales = $this->sales->totalsByEvent();

        $rows = [];
        foreach ($events as $event) {
            if (!$event->startsIn($year)) {
                continue;
            }
            $result = SalesFigures::of($sales[(string) $event->id()] ?? SalesTotals::zero(), $shares->totalOf($event), $consumed[(string) $event->id()] ?? null, $event->donationsTotal());
            $rows[] = [
                'id' => (string) $event->id(),
                'name' => $event->name(),
                'startDate' => $event->period()->start()->format('Y-m-d'),
                'turnover' => $result->turnover->amount(),
                'result' => $result->result->amount(),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['result'] <=> $a['result']);

        return $rows;
    }

    /**
     * @param list<Product> $products
     *
     * @return array<string, string|null>
     */
    private function typeNamesOf(array $products): array
    {
        $typeNames = [];
        foreach ($products as $product) {
            $typeNames[(string) $product->id()] = $product->type()->name();
        }

        return $typeNames;
    }

    /**
     * @param list<ProductSales>         $sales
     * @param array<string, string|null> $typeNames
     *
     * @return list<array{name: ?string, quantity: int, sales: int}>
     */
    private function salesByType(array $sales, array $typeNames): array
    {
        $types = [];
        foreach ($sales as $product) {
            $name = $typeNames[(string) $product->productId] ?? null;
            $key = $name ?? '';
            $types[$key] ??= ['name' => $name, 'quantity' => 0, 'sales' => 0];
            $types[$key]['quantity'] += $product->quantity;
            $types[$key]['sales'] += $product->sales->amount();
        }
        $types = array_values($types);
        usort($types, static fn (array $a, array $b): int => $b['sales'] <=> $a['sales']);

        return $types;
    }
}
