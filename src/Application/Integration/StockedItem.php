<?php

declare(strict_types=1);

namespace App\Application\Integration;

final readonly class StockedItem
{
    public function __construct(
        public ?string $variant,
        public string $sku,
        public int $available,
    ) {
    }
}
