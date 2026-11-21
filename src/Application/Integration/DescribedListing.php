<?php

declare(strict_types=1);

namespace App\Application\Integration;

final readonly class DescribedListing
{
    /**
     * @param list<ExternalLine>    $lines
     * @param array<string, string> $descriptions
     */
    public function __construct(
        public string $externalRef,
        public array $lines,
        public array $descriptions,
    ) {
    }
}
