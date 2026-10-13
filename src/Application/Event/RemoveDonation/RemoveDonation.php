<?php

declare(strict_types=1);

namespace App\Application\Event\RemoveDonation;

final readonly class RemoveDonation
{
    public function __construct(
        public string $eventId,
        public string $donationId,
    ) {
    }
}
