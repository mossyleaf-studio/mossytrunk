<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Event\Event;
use App\Domain\Planning\Exception\DayAndEvent;
use App\Domain\Planning\Exception\DayOrEventMissing;
use App\Domain\Shared\BusinessTime;

final class TargetDay
{
    public static function chosen(?\DateTimeImmutable $day, ?Event $event): ?\DateTimeImmutable
    {
        if (null === $day && null === $event) {
            throw new DayOrEventMissing();
        }
        if (null !== $day && null !== $event) {
            throw new DayAndEvent();
        }

        return null === $day ? null : BusinessTime::midnightOf($day);
    }

    public static function of(?\DateTimeImmutable $day, ?Event $event): \DateTimeImmutable
    {
        return BusinessTime::midnightOf($day ?? $event?->period()->start() ?? throw new DayOrEventMissing());
    }
}
