<?php

declare(strict_types=1);

namespace App\Application\Planning\ReviseRestock;

final readonly class ReviseRestock
{
    public function __construct(
        public string $restockId,
        public string $supplierId,
        public ?string $note,
        public ?string $eventId,
        public int $leadDays,
    ) {
    }
}
