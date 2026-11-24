<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final readonly class ProductSales
{
    public function __construct(
        public string $label,
        public ?Ulid $productId,
        public string $productName,
        public ?string $variant,
        public int $quantity,
        public Money $gross,
        public Money $sales,
        public Money $cost,
        public bool $unknownCost,
        public int $discountedUnits,
        public Money $discountedGross,
    ) {
    }

    public function fullPriceUnits(): int
    {
        return $this->quantity - $this->discountedUnits;
    }

    public function discount(): Money
    {
        return $this->gross->subtract($this->sales);
    }

    public function margin(): Money
    {
        return $this->sales->subtract($this->cost);
    }

    /**
     * @param list<self> $sales
     *
     * @return list<Ulid>
     */
    public static function productIds(array $sales): array
    {
        return array_values(array_filter(array_map(static fn (self $line): ?Ulid => $line->productId, $sales)));
    }
}
