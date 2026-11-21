<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

final readonly class PublishedCatalogue
{
    /**
     * @param list<DescriptionReport> $descriptions
     */
    public function __construct(
        public string $label,
        public int $products,
        public int $created,
        public int $updated,
        public int $retired,
        public array $descriptions,
    ) {
    }
}
