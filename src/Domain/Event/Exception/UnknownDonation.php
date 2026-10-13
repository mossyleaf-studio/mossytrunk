<?php

declare(strict_types=1);

namespace App\Domain\Event\Exception;

final class UnknownDonation extends InvalidEvent
{
    public function __construct(string $donationId)
    {
        parent::__construct('event.unknown_donation', ['id' => $donationId]);
    }
}
