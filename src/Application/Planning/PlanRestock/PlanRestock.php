<?php

declare(strict_types=1);

namespace App\Application\Planning\PlanRestock;

final readonly class PlanRestock
{
    public function __construct(
        public string $supplierId,
        public ?string $note,
        public ?string $eventId,
        public int $leadDays,
    ) {
    }
}
