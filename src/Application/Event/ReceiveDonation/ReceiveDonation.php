<?php

declare(strict_types=1);

namespace App\Application\Event\ReceiveDonation;

final readonly class ReceiveDonation
{
    public function __construct(
        public string $eventId,
        public ?string $label,
        public int $amountCents,
    ) {
    }
}
