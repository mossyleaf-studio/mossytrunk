<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

final readonly class PublishedCatalogue
{
    public function __construct(
        public string $label,
        public int $products,
        public int $created,
        public int $updated,
        public int $retired,
    ) {
    }
}
