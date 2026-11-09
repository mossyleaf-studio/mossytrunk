<?php

declare(strict_types=1);

namespace App\Application\Reporting\ProductReport;

use App\Application\Product\ProductMovements;
use App\Application\Reporting\ProductSalesLedger;
use App\Application\Reporting\ReportPeriod;
use App\Domain\Discount\DiscountRule;
use App\Domain\Discount\DiscountRuleRepository;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use App\Domain\Stock\StockItem;
use App\Domain\Stock\StockRepository;
use Symfony\Component\Uid\Ulid;

final readonly class GetProductReportHandler
{
    public function __construct(
        private ProductRepository $products,
        private ProductSalesLedger $sales,
        private StockRepository $stock,
        private DiscountRuleRepository $rules,
        private ProductMovements $movements,
        private ReportPeriod $periods,
    ) {
    }

    public function __invoke(string $productId, string $period): ProductReportView
    {
        $product = $this->products->get(Ulid::fromString($productId));
        $range = $this->periods->resolve($period);
        $stockItems = $this->stock->ofProduct($product->id());
        $onHand = array_sum(array_map(static fn (StockItem $item): int => $item->onHand(), $stockItems));
        $months = self::withStockLevels($this->monthlySales($product, $range), $this->monthlyFlows($product, $stockItems), $onHand);
        $discounts = $this->discountsOf($product, $range);

        return new ProductReportView(
            [
                'id' => (string) $product->id(),
                'name' => $product->displayName(),
                'typeName' => $product->type()->name(),
                'sellingPrice' => $product->sellingPrice()->amount(),
                'onHand' => $onHand,
            ],
            ['from' => $range->start()->format('Y-m-d'), 'to' => $range->end()->format('Y-m-d'), 'days' => $range->days()],
            self::series($months),
            $discounts,
            self::coveredDays($discounts),
        );
    }

    /**
     * @return array<string, array{units: int, gross: int, revenue: int}>
     */
    private function monthlySales(Product $product, DateRange $range): array
    {
        $months = array_fill_keys(ReportPeriod::months($range), ['units' => 0, 'gross' => 0, 'revenue' => 0]);
        foreach ($this->sales->monthlyOf($product->id()) as $month => $sold) {
            if (isset($months[$month])) {
                $months[$month] = ['units' => $sold->quantity, 'gross' => $sold->gross->amount(), 'revenue' => $sold->sales->amount()];
            }
        }

        return $months;
    }

    /**
     * @param list<StockItem> $stockItems
     *
     * @return array<string, array{received: int, sold: int, lost: int, used: int, net: int}>
     */
    private function monthlyFlows(Product $product, array $stockItems): array
    {
        $flows = [];
        foreach ($this->movements->of($product, $stockItems) as $movement) {
            $month = BusinessTime::month(new \DateTimeImmutable($movement['date']));
            $flows[$month] ??= self::noFlow();
            $flows[$month]['net'] += $movement['quantity'];
            $flows[$month][self::flowBucket($movement['kind'])] += abs($movement['quantity']);
        }

        return $flows;
    }

    /**
     * @return 'received'|'sold'|'lost'|'used'
     */
    private static function flowBucket(string $movementKind): string
    {
        return match ($movementKind) {
            'sale' => 'sold',
            'loss' => 'lost',
            'supply' => 'used',
            default => 'received',
        };
    }

    /**
     * @return array{received: int, sold: int, lost: int, used: int, net: int}
     */
    private static function noFlow(): array
    {
        return ['received' => 0, 'sold' => 0, 'lost' => 0, 'used' => 0, 'net' => 0];
    }

    /**
     * @param array<string, array{units: int, gross: int, revenue: int}>                     $months
     * @param array<string, array{received: int, sold: int, lost: int, used: int, net: int}> $flows
     *
     * @return array<string, array{units: int, gross: int, revenue: int, received: int, sold: int, lost: int, used: int, onHand: int}>
     */
    private static function withStockLevels(array $months, array $flows, int $onHand): array
    {
        $lastMonth = array_key_last($months);
        $level = $onHand - array_sum(array_map(static fn (array $flow): int => $flow['net'], array_filter($flows, static fn (string $month): bool => $month > $lastMonth, \ARRAY_FILTER_USE_KEY)));
        $levels = [];
        foreach (array_reverse($months, true) as $month => $sales) {
            $flow = $flows[$month] ?? self::noFlow();
            $levels[$month] = [...$sales, 'received' => $flow['received'], 'sold' => $flow['sold'], 'lost' => $flow['lost'], 'used' => $flow['used'], 'onHand' => $level];
            $level -= $flow['net'];
        }

        return array_reverse($levels, true);
    }

    /**
     * @param array<string, array{units: int, gross: int, revenue: int, received: int, sold: int, lost: int, used: int, onHand: int}> $months
     *
     * @return list<array{month: string, units: int, gross: int, revenue: int, received: int, sold: int, lost: int, used: int, onHand: int}>
     */
    private static function series(array $months): array
    {
        $series = [];
        foreach ($months as $month => $figures) {
            $series[] = ['month' => (string) $month, ...$figures];
        }

        return $series;
    }

    /**
     * @return list<array{id: string, name: string, from: string, to: string, days: int}>
     */
    private function discountsOf(Product $product, DateRange $range): array
    {
        $discounts = [];
        foreach ($this->rules->all() as $rule) {
            $within = $rule->concerns($product) ? $rule->validity()->within($range) : null;
            if (null !== $within) {
                $discounts[] = self::discount($rule, $within);
            }
        }
        usort($discounts, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $discounts;
    }

    /**
     * @return array{id: string, name: string, from: string, to: string, days: int}
     */
    private static function discount(DiscountRule $rule, DateRange $within): array
    {
        return ['id' => (string) $rule->id(), 'name' => $rule->name(), 'from' => $within->start()->format('Y-m-d'), 'to' => $within->end()->format('Y-m-d'), 'days' => $within->days()];
    }

    /**
     * @param list<array{id: string, name: string, from: string, to: string, days: int}> $discounts
     */
    private static function coveredDays(array $discounts): int
    {
        $days = 0;
        $reached = null;
        foreach ($discounts as $discount) {
            $from = null === $reached || $discount['from'] > $reached ? $discount['from'] : (new \DateTimeImmutable($reached))->modify('+1 day')->format('Y-m-d');
            if ($from <= $discount['to']) {
                $days += (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($discount['to']))->days + 1;
            }
            $reached = null === $reached || $discount['to'] > $reached ? $discount['to'] : $reached;
        }

        return $days;
    }
}
