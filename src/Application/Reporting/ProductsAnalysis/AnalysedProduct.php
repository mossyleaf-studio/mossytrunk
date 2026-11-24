<?php

declare(strict_types=1);

namespace App\Application\Reporting\ProductsAnalysis;

use App\Domain\Product\Product;
use App\Domain\Reporting\Analysis\Exposure;
use App\Domain\Reporting\ProductSales;
use App\Domain\Shared\Money;

final readonly class AnalysedProduct
{
    public function __construct(
        public Product $product,
        public Exposure $exposure,
        public ?ProductSales $sold,
        public int $onHand,
    ) {
    }

    public function typeKey(): string
    {
        return (string) $this->product->type()->id();
    }

    public function margin(): Money
    {
        return $this->sold?->margin() ?? Money::zero();
    }
}
