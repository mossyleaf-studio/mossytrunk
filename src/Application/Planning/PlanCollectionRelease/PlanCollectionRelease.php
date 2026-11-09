<?php

declare(strict_types=1);

namespace App\Application\Planning\PlanCollectionRelease;

final readonly class PlanCollectionRelease
{
    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     */
    public function __construct(
        public string $collectionId,
        public ?\DateTimeImmutable $day,
        public ?string $eventId,
        public array $steps,
    ) {
    }
}
