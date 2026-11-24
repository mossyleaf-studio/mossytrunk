<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

use Symfony\Component\Uid\Ulid;

final readonly class EventUnits
{
    public function __construct(
        public Ulid $productId,
        public ?Ulid $eventId,
        public int $units,
        public string $firstSale,
        public string $lastSale,
    ) {
    }
}
