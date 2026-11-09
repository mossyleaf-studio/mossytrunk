<?php

declare(strict_types=1);

namespace App\Application\Planning\GetReleaseTemplate;

final readonly class ReleaseTemplateView
{
    /**
     * @param list<array{label: string, days: int}> $steps
     */
    public function __construct(
        public array $steps,
    ) {
    }
}
