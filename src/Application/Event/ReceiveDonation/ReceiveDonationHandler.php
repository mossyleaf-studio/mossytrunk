<?php

declare(strict_types=1);

namespace App\Application\Event\ReceiveDonation;

use App\Application\Transaction;
use App\Domain\Event\EventRepository;
use App\Domain\Shared\Money;
use Symfony\Component\Uid\Ulid;

final readonly class ReceiveDonationHandler
{
    public function __construct(
        private EventRepository $events,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(ReceiveDonation $command): Ulid
    {
        $event = $this->events->get(Ulid::fromString($command->eventId));
        $donation = $event->receiveDonation($command->label, Money::cents($command->amountCents));
        $this->transaction->commit();

        return $donation->id();
    }
}
