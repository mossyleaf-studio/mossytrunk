<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

use Symfony\Component\Uid\Ulid;

final readonly class UnitsByEvent
{
    /**
     * @param array<string, list<EventUnits>> $byProduct
     */
    private function __construct(private array $byProduct)
    {
    }

    /**
     * @param list<EventUnits> $rows
     */
    public static function of(array $rows): self
    {
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(string) $row->productId][] = $row;
        }

        return new self($byProduct);
    }

    public function firstSale(Ulid $productId): ?string
    {
        $days = array_map(static fn (EventUnits $row): string => $row->firstSale, $this->rowsOf($productId));

        return [] === $days ? null : min($days);
    }

    public function lastSale(Ulid $productId): ?string
    {
        $days = array_map(static fn (EventUnits $row): string => $row->lastSale, $this->rowsOf($productId));

        return [] === $days ? null : max($days);
    }

    /**
     * @param list<SoldEvent> $events
     *
     * @return list<int|null>
     */
    public function row(Ulid $productId, array $events, Exposure $exposure): array
    {
        return array_map(fn (SoldEvent $event): ?int => $exposure->onSaleDuring($event) ? $this->unitsAt($productId, $event->id) : null, $events);
    }

    public function offEvent(Ulid $productId): int
    {
        return $this->unitsAt($productId, null);
    }

    private function unitsAt(Ulid $productId, ?Ulid $eventId): int
    {
        return array_sum(array_map(static fn (EventUnits $row): int => $row->eventId?->toBase32() === $eventId?->toBase32() ? $row->units : 0, $this->rowsOf($productId)));
    }

    /**
     * @return list<EventUnits>
     */
    private function rowsOf(Ulid $productId): array
    {
        return $this->byProduct[(string) $productId] ?? [];
    }
}
