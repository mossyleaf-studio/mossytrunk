<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Shared\DateRange;
use Symfony\Component\Uid\Ulid;

interface EventRepository
{
    public function add(Event $event): void;

    public function get(Ulid $id): Event;

    public function findCovering(\DateTimeImmutable $moment): ?Event;

    public function findOverlapping(DateRange $period, ?Ulid $except = null): ?Event;

    /**
     * @return list<Event>
     */
    public function all(): array;

    /**
     * @return list<Event>
     */
    public function notOverOn(\DateTimeImmutable $today): array;
}
