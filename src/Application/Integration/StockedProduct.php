<?php

declare(strict_types=1);

namespace App\Application\Integration;

use App\Domain\Shared\Money;

final readonly class StockedProduct
{
    /**
     * @param list<StockedItem> $items
     */
    public function __construct(
        public string $id,
        public string $reference,
        public string $name,
        public string $category,
        public string $displayName,
        public Money $price,
        public array $items,
    ) {
    }
}
