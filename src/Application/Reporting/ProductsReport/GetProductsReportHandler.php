<?php

declare(strict_types=1);

namespace App\Application\Reporting\ProductsReport;

use App\Application\Reporting\ProductSalesLedger;
use App\Application\Reporting\ReportPeriod;
use App\Application\Reporting\SoldFigures;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Reporting\ProductSales;
use App\Domain\Stock\StockRepository;

final readonly class GetProductsReportHandler
{
    public function __construct(
        private ProductSalesLedger $sales,
        private ProductRepository $products,
        private StockRepository $stock,
        private ReportPeriod $periods,
    ) {
    }

    public function __invoke(string $period): ProductsReportView
    {
        $range = $this->periods->resolve($period);

        $sold = [];
        foreach ($this->sales->within($range) as $product) {
            $sold[(string) $product->productId] = $product;
        }

        $onHand = [];
        foreach ($this->stock->all() as $item) {
            $key = (string) $item->product()->id();
            $onHand[$key] = ($onHand[$key] ?? 0) + $item->onHand();
        }

        $rows = [];
        foreach ($this->products->articles() as $product) {
            $key = (string) $product->id();
            if (!isset($sold[$key])) {
                continue;
            }
            $rows[] = self::row($product, $sold[$key], $onHand[$key] ?? 0);
        }
        usort($rows, static fn (array $a, array $b): int => [$b['revenue'], $a['name']] <=> [$a['revenue'], $b['name']]);

        return new ProductsReportView(
            ['choice' => $period, 'from' => $range->start()->format('Y-m-d'), 'to' => $range->end()->format('Y-m-d')],
            $this->periods->years(),
            $rows,
            [
                'units' => array_sum(array_column($rows, 'units')),
                'discountedUnits' => array_sum(array_column($rows, 'discountedUnits')),
                'gross' => array_sum(array_column($rows, 'gross')),
                'revenue' => array_sum(array_column($rows, 'revenue')),
                'discount' => array_sum(array_column($rows, 'discount')),
                'margin' => array_sum(array_column($rows, 'margin')),
            ],
        );
    }

    /**
     * @return array{id: string, name: string, typeId: string, typeName: string, units: int, fullPriceUnits: int, discountedUnits: int, gross: int, discountedGross: int, discount: int, revenue: int, cost: int, margin: int, unknownCost: bool, onHand: int}
     */
    private static function row(Product $product, ProductSales $sold, int $onHand): array
    {
        return [
            'id' => (string) $product->id(),
            'name' => $product->displayName(),
            'typeId' => (string) $product->type()->id(),
            'typeName' => $product->type()->name(),
            ...SoldFigures::of($sold),
            'onHand' => $onHand,
        ];
    }
}
