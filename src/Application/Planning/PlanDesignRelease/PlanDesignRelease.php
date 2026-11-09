<?php

declare(strict_types=1);

namespace App\Application\Planning\PlanDesignRelease;

final readonly class PlanDesignRelease
{
    /**
     * @param list<array{label: string, days: int, done?: bool}> $steps
     */
    public function __construct(
        public string $designId,
        public ?\DateTimeImmutable $day,
        public ?string $eventId,
        public array $steps,
    ) {
    }
}
