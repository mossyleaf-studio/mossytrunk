<?php

declare(strict_types=1);

namespace App\Presentation\Api\Event;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DonationPayload
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $label = null,
        #[Assert\Positive(message: 'donation.amount.positive')]
        public int $amount = 0,
    ) {
    }
}
