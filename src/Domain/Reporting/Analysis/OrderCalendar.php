<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

final readonly class OrderCalendar
{
    /**
     * @param list<SellingDay>   $days
     * @param list<SoldEvent>    $events
     * @param array<string, int> $eventOrders
     */
    private function __construct(
        private array $days,
        private array $events,
        private array $eventOrders,
    ) {
    }

    /**
     * @param list<SellingDay> $days
     */
    public static function of(array $days): self
    {
        $events = [];
        $eventOrders = [];
        foreach ($days as $day) {
            if (null !== $day->event) {
                $key = (string) $day->event->id;
                $events[$key] = $day->event;
                $eventOrders[$key] = ($eventOrders[$key] ?? 0) + $day->orders;
            }
        }
        usort($events, static fn (SoldEvent $a, SoldEvent $b): int => [$a->from, $a->to, (string) $a->id] <=> [$b->from, $b->to, (string) $b->id]);

        return new self($days, $events, $eventOrders);
    }

    /**
     * @return list<SellingDay>
     */
    public function days(): array
    {
        return $this->days;
    }

    public function orders(): int
    {
        return $this->sum(static fn (): bool => true);
    }

    public function offEventOrders(): int
    {
        return $this->sum(static fn (SellingDay $day): bool => null === $day->event);
    }

    public function between(string $from, string $to): int
    {
        return $this->sum(static fn (SellingDay $day): bool => $day->day >= $from && $day->day <= $to);
    }

    public function since(string $from): int
    {
        return $this->sum(static fn (SellingDay $day): bool => $day->day >= $from);
    }

    /**
     * @return list<SoldEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function ordersAt(SoldEvent $event): int
    {
        return $this->eventOrders[(string) $event->id] ?? 0;
    }

    public function busiestEvent(): ?SoldEvent
    {
        $busiest = null;
        foreach ($this->events as $event) {
            if (null === $busiest || $this->ordersAt($event) > $this->ordersAt($busiest)) {
                $busiest = $event;
            }
        }

        return $busiest;
    }

    public function busiestEventOrders(): int
    {
        $busiest = $this->busiestEvent();

        return null === $busiest ? 0 : $this->ordersAt($busiest);
    }

    /**
     * @param callable(SellingDay): bool $counted
     */
    private function sum(callable $counted): int
    {
        return array_sum(array_map(static fn (SellingDay $day): int => $counted($day) ? $day->orders : 0, $this->days));
    }
}
