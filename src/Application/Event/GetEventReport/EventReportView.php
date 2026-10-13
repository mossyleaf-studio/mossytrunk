<?php

declare(strict_types=1);

namespace App\Application\Event\GetEventReport;

use App\Domain\Event\Event;
use App\Domain\Event\ExpenseShare;
use App\Domain\Event\ExpenseShares;
use App\Domain\Product\Product;
use App\Domain\Reporting\ProductSales;
use App\Domain\Reporting\SalesFigures;
use App\Domain\Reporting\UrssafContribution;

final readonly class EventReportView
{
    /**
     * @param array{count: int, grossSales: int, discounts: int, turnover: int, costOfGoods: int, groups: list<array{type: string|null, products: list<array{name: string, variants: list<array{variant: string, quantity: int, sales: int, cost: int, unknownCost: bool}>, quantity: int, sales: int, cost: int, unknownCost: bool}>, quantity: int, sales: int, cost: int, unknownCost: bool}>} $orders
     * @param array{items: list<array{label: string, amount: int, sharedBy: int}>, total: int}                                                                                                                                                                                                                                                                                                    $expenses
     * @param array{rate: int|float, base: int, amount: int}                                                                                                                                                                                                                                                                                                                                      $urssaf
     * @param array{turnover: int, costOfGoods: int, supplies: int, consumedSupplies: int, channelCosts: int, expenses: int, donations: int, urssaf: int, result: int}                                                                                                                                                                                                                            $total
     */
    public function __construct(
        public array $orders,
        public array $expenses,
        public array $urssaf,
        public array $total,
    ) {
    }

    /**
     * @param list<ProductSales>     $productSales
     * @param array<string, Product> $products
     */
    public static function of(Event $event, SalesFigures $result, ExpenseShares $shares, array $productSales, array $products = []): self
    {
        return new self(
            orders: [
                'count' => $result->orderCount,
                'grossSales' => $result->grossSales->amount(),
                'discounts' => $result->discounts->amount(),
                'turnover' => $result->turnover->amount(),
                'costOfGoods' => $result->costOfGoods->amount(),
                'groups' => OrderRecap::group($productSales, $products),
            ],
            expenses: [
                'items' => array_map(static fn (ExpenseShare $share): array => ['label' => $share->expense->label(), 'amount' => $share->amount->amount(), 'sharedBy' => $share->sharedBy], $shares->of($event)),
                'total' => $result->expenses->amount(),
            ],
            urssaf: [
                'rate' => UrssafContribution::RATE_BASIS_POINTS / 100,
                'base' => $result->turnover->amount(),
                'amount' => $result->urssaf->amount(),
            ],
            total: [
                'turnover' => $result->turnover->amount(),
                'costOfGoods' => $result->costOfGoods->amount(),
                'supplies' => $result->supplies->amount(),
                'consumedSupplies' => $result->consumedSupplies->amount(),
                'channelCosts' => $result->channelCosts->amount(),
                'expenses' => $result->expenses->amount(),
                'donations' => $result->donations->amount(),
                'urssaf' => $result->urssaf->amount(),
                'result' => $result->result->amount(),
            ],
        );
    }
}
