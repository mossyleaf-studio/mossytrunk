<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

final readonly class Exposure
{
    public const int ENOUGH_ORDERS = 150;

    private function __construct(
        public string $arrival,
        public ?string $soldOutAfter,
        public int $units,
        public int $orders,
        public int $sinceArrival,
        public int $periodOrders,
    ) {
    }

    public static function of(OrderCalendar $calendar, string $arrival, int $units, ?string $soldOutAfter = null): self
    {
        $sinceArrival = $calendar->since($arrival);
        $orders = null === $soldOutAfter ? $sinceArrival : $calendar->between($arrival, $soldOutAfter);

        return new self($arrival, $soldOutAfter, $units, $orders, $sinceArrival, $calendar->orders());
    }

    public function per100Orders(): ?float
    {
        return 0 === $this->orders ? null : round($this->units * 100 / $this->orders, 1);
    }

    public function tooEarly(): bool
    {
        return $this->tooFewOrders() || $this->arrivedLate();
    }

    public function tooFewOrders(): bool
    {
        return $this->sinceArrival < self::ENOUGH_ORDERS;
    }

    public function arrivedLate(): bool
    {
        return 2 * $this->sinceArrival < $this->periodOrders;
    }

    public function coverOrders(int $onHand): ?int
    {
        return 0 === $this->units ? null : intdiv(max(0, $onHand) * $this->orders, $this->units);
    }

    public function onSaleDuring(SoldEvent $event): bool
    {
        return $event->to >= $this->arrival && (null === $this->soldOutAfter || $event->from <= $this->soldOutAfter);
    }
}
