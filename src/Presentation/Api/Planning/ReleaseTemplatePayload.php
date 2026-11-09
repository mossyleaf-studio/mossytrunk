<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReleaseTemplatePayload
{
    /**
     * @param list<StepPayload> $steps
     */
    public function __construct(
        #[Assert\Valid]
        public array $steps = [],
    ) {
    }

    /**
     * @return list<array{label: string, days: int}>
     */
    public function toSteps(): array
    {
        return array_map(static fn (array $step) => ['label' => $step['label'], 'days' => $step['days']], StepPayload::toSteps($this->steps));
    }
}
