<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

use App\Domain\Product\Product;
use App\Domain\Shared\BusinessTime;
use App\Domain\Stock\StockItem;
use App\Domain\Stock\StockLot;

final readonly class Arrival
{
    private function __construct(public string $day)
    {
    }

    /**
     * @param list<StockItem> $stock
     */
    public static function of(Product $product, array $stock, ?string $firstSale): ?self
    {
        if ($product->isSupply() || $product->isArchived()) {
            return null;
        }
        $days = array_map(static fn (StockLot $lot): string => BusinessTime::day($lot->receivedAt()), array_merge(...array_map(static fn (StockItem $item): array => $item->lots(), $stock)));
        if (null !== $firstSale) {
            $days[] = $firstSale;
        }

        return [] === $days ? null : new self(min($days));
    }

    public function isBy(string $day): bool
    {
        return $this->day <= $day;
    }
}
