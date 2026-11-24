<?php

declare(strict_types=1);

namespace App\Application\Reporting\ProductsAnalysis;

final readonly class ProductsAnalysisView
{
    /**
     * @param array{choice: string, from: string, to: string, current: bool}                                                                                                                                                                                                                                                                                                                                              $period
     * @param list<array{id: string, name: string, from: string, to: string, orders: int}>                                                                                                                                                                                                                                                                                                                                $events
     * @param array{id: string, name: string, from: string, to: string, orders: int}|null                                                                                                                                                                                                                                                                                                                                 $busiestEvent
     * @param list<array{id: string, name: string, judged: int, popularFrom: float|null, unitMarginFrom: int|null}>                                                                                                                                                                                                                                                                                                       $types
     * @param list<array{id: string, name: string, typeId: string, typeName: string, units: int, revenue: int, margin: int, unknownCost: bool, unitMargin: int|null, availableSince: string, exposure: int, per100Orders: float|null, class: string, quadrant: string|null, cumulativeMargin: int, onHand: int, coverOrders: int|null, stock: string|null, byEvent: list<int|null>, offEvent: int, verdict: string|null}> $products
     * @param array{units: int, revenue: int, margin: int, onHand: int}                                                                                                                                                                                                                                                                                                                                                   $totals
     */
    public function __construct(
        public array $period,
        public int $orders,
        public int $offEventOrders,
        public array $events,
        public ?array $busiestEvent,
        public array $types,
        public array $products,
        public array $totals,
    ) {
    }
}
