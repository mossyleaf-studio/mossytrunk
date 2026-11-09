<?php

declare(strict_types=1);

namespace App\Application\Planning\ReplanRelease;

final readonly class ReplanRelease
{
    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     */
    public function __construct(
        public string $releaseId,
        public ?\DateTimeImmutable $day,
        public ?string $eventId,
        public array $steps,
    ) {
    }
}
