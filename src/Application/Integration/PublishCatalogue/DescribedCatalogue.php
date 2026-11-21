<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

use App\Application\Integration\StockedProduct;

final readonly class DescribedCatalogue
{
    /**
     * @param list<StockedProduct>    $products
     * @param list<DescriptionReport> $reports
     */
    public function __construct(
        public array $products,
        public array $reports = [],
    ) {
    }

    public function describedBy(string $label, ListingTexts $texts): self
    {
        $products = [];
        $described = 0;
        $notReached = 0;
        foreach ($this->products as $product) {
            $found = [] === $product->descriptions ? $texts->of($product->id) : [];
            $products[] = [] === $found ? $product : $product->describedIn($found);
            $described += [] === $found ? 0 : 1;
            $notReached += [] === $product->descriptions && !$texts->reaches($product->id) ? 1 : 0;
        }

        return new self($products, [...$this->reports, $texts->report($label, $described, $notReached)]);
    }
}
