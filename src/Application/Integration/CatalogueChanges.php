<?php

declare(strict_types=1);

namespace App\Application\Integration;

final readonly class CatalogueChanges
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $retired,
    ) {
    }
}
