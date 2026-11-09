<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Transaction;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class ReleasePlanning
{
    public function __construct(
        private ReleaseRepository $releases,
        private EventRepository $events,
        private Transaction $transaction,
    ) {
    }

    public function event(?string $eventId): ?Event
    {
        return null === $eventId ? null : $this->events->get(Ulid::fromString($eventId));
    }

    public function keep(Release $release): Ulid
    {
        $this->releases->add($release);
        $this->transaction->commit();

        return $release->id();
    }
}
