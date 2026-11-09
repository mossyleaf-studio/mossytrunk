<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RestockPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'supplier.invalid')]
        #[Assert\Ulid(message: 'supplier.invalid')]
        public string $supplierId = '',
        #[Assert\Length(max: 255)]
        public ?string $note = null,
        public ?\DateTimeImmutable $day = null,
        #[Assert\Ulid(message: 'event.invalid')]
        public ?string $eventId = null,
        public int $leadDays = 0,
    ) {
    }
}
