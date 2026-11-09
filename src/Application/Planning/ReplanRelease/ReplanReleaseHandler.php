<?php

declare(strict_types=1);

namespace App\Application\Planning\ReplanRelease;

use App\Application\Transaction;
use App\Domain\Event\EventRepository;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class ReplanReleaseHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private EventRepository $events,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(ReplanRelease $command): void
    {
        $release = $this->releases->get(Ulid::fromString($command->releaseId));
        $release->schedule($command->day, null === $command->eventId ? null : $this->events->get(Ulid::fromString($command->eventId)));
        $release->replan($command->steps);
        $this->transaction->commit();
    }
}
