<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Shared\Money;

final readonly class SalesFigures
{
    private function __construct(
        public int $orderCount,
        public Money $grossSales,
        public Money $discounts,
        public Money $shipping,
        public Money $turnover,
        public Money $costOfGoods,
        public Money $supplies,
        public Money $consumedSupplies,
        public Money $channelCosts,
        public Money $expenses,
        public Money $donations,
        public Money $urssaf,
        public Money $result,
    ) {
    }

    public static function of(SalesTotals $sales, Money $expenses, ?Money $consumedSupplies = null, ?Money $donations = null): self
    {
        $turnover = $sales->turnover();
        $consumedSupplies ??= Money::zero();
        $donations ??= Money::zero();
        $urssaf = UrssafContribution::on($turnover);

        return new self(
            $sales->orderCount,
            $sales->grossSales,
            $sales->discounts,
            $sales->shipping,
            $turnover,
            $sales->costOfGoods,
            $sales->supplies,
            $consumedSupplies,
            $sales->channelCosts,
            $expenses,
            $donations,
            $urssaf,
            $turnover->subtract($sales->costOfGoods)->subtract($sales->supplies)->subtract($consumedSupplies)->subtract($sales->channelCosts)->subtract($expenses)->subtract($urssaf)->add($donations),
        );
    }

    public static function zero(): self
    {
        return self::of(SalesTotals::zero(), Money::zero());
    }

    public function add(self $other): self
    {
        return new self(
            $this->orderCount + $other->orderCount,
            $this->grossSales->add($other->grossSales),
            $this->discounts->add($other->discounts),
            $this->shipping->add($other->shipping),
            $this->turnover->add($other->turnover),
            $this->costOfGoods->add($other->costOfGoods),
            $this->supplies->add($other->supplies),
            $this->consumedSupplies->add($other->consumedSupplies),
            $this->channelCosts->add($other->channelCosts),
            $this->expenses->add($other->expenses),
            $this->donations->add($other->donations),
            $this->urssaf->add($other->urssaf),
            $this->result->add($other->result),
        );
    }

    /**
     * @return array{orderCount: int, grossSales: int, discounts: int, shipping: int, turnover: int, costOfGoods: int, supplies: int, consumedSupplies: int, channelCosts: int, expenses: int, donations: int, urssaf: int, result: int}
     */
    public function toArray(): array
    {
        return [
            'orderCount' => $this->orderCount,
            'grossSales' => $this->grossSales->amount(),
            'discounts' => $this->discounts->amount(),
            'shipping' => $this->shipping->amount(),
            'turnover' => $this->turnover->amount(),
            'costOfGoods' => $this->costOfGoods->amount(),
            'supplies' => $this->supplies->amount(),
            'consumedSupplies' => $this->consumedSupplies->amount(),
            'channelCosts' => $this->channelCosts->amount(),
            'expenses' => $this->expenses->amount(),
            'donations' => $this->donations->amount(),
            'urssaf' => $this->urssaf->amount(),
            'result' => $this->result->amount(),
        ];
    }
}
