<?php

declare(strict_types=1);

namespace App\Application\Event\RemoveDonation;

use App\Application\Transaction;
use App\Domain\Event\EventRepository;
use Symfony\Component\Uid\Ulid;

final readonly class RemoveDonationHandler
{
    public function __construct(
        private EventRepository $events,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(RemoveDonation $command): void
    {
        $event = $this->events->get(Ulid::fromString($command->eventId));
        $event->removeDonation(Ulid::fromString($command->donationId));
        $this->transaction->commit();
    }
}
