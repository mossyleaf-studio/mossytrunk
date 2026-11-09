<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StepPayload
{
    public function __construct(
        public string $label = '',
        #[Assert\Type('int')]
        public int $days = 1,
        public bool $done = false,
    ) {
    }

    /**
     * @param list<self> $steps
     *
     * @return list<array{label: string, days: int, done: bool}>
     */
    public static function toSteps(array $steps): array
    {
        return array_map(static fn (self $step) => ['label' => $step->label, 'days' => $step->days, 'done' => $step->done], $steps);
    }
}
