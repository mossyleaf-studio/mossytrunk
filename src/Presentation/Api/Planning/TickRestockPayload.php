<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TickRestockPayload
{
    public function __construct(
        #[Assert\NotNull(message: 'restock.ordered.required')]
        public ?bool $ordered = null,
    ) {
    }
}
