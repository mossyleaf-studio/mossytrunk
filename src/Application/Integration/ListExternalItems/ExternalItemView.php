<?php

declare(strict_types=1);

namespace App\Application\Integration\ListExternalItems;

final readonly class ExternalItemView
{
    /**
     * @param list<array{productId: string, name: string, variant: ?string, quantity: int}> $components
     */
    public function __construct(
        public string $id,
        public string $externalRef,
        public string $label,
        public ?string $variation,
        public array $components,
        public string $seenAt,
    ) {
    }
}
