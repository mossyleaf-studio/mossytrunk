<?php

declare(strict_types=1);

namespace App\Application\Integration;

final readonly class StockedCollection
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
