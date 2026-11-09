<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TickReleaseStepPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'release.step.required')]
        public string $step = '',
        #[Assert\NotNull(message: 'release.done.required')]
        public ?bool $done = null,
    ) {
    }
}
