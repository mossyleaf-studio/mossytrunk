<?php

declare(strict_types=1);

namespace App\Application\Integration;

use App\Domain\Shared\Money;

final readonly class StockedProduct
{
    /**
     * @param list<StockedItem>     $items
     * @param array<string, string> $descriptions
     */
    public function __construct(
        public string $id,
        public string $reference,
        public string $name,
        public string $category,
        public string $displayName,
        public Money $price,
        public array $items,
        public array $descriptions = [],
    ) {
    }

    /**
     * @param array<string, string> $descriptions
     */
    public function describedIn(array $descriptions): self
    {
        return new self($this->id, $this->reference, $this->name, $this->category, $this->displayName, $this->price, $this->items, $descriptions);
    }
}
