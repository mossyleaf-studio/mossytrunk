<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

final readonly class DescriptionReport
{
    public function __construct(
        public string $label,
        public bool $read,
        public int $listings,
        public int $described,
        public int $notReached,
        public int $withoutText,
        public int $unlinked,
        public int $bundles,
    ) {
    }
}
