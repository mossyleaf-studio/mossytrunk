<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReleasePayload
{
    /**
     * @param list<StepPayload> $steps
     */
    public function __construct(
        public ?\DateTimeImmutable $day = null,
        #[Assert\Ulid(message: 'event.invalid')]
        public ?string $eventId = null,
        #[Assert\Valid]
        public array $steps = [],
    ) {
    }

    /**
     * @return list<array{label: string, days: int, done: bool}>
     */
    public function steps(): array
    {
        return StepPayload::toSteps($this->steps);
    }
}
