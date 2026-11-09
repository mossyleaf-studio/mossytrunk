<?php

declare(strict_types=1);

namespace App\Application\Integration\ImportSales;

use App\Application\Sales\OrderChannel;
use App\Application\Stock\StockKeeper;
use App\Domain\Discount\DiscountRule;
use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Integration\ServiceConnection;
use App\Domain\Sales\SalesChannel;
use App\Domain\Shared\BusinessTime;
use App\Domain\Stock\StockCheck;

final class SalesImport
{
    /** @var array<string, Event|null> */
    private array $eventsByDay = [];

    /** @var array<string, SalesChannel|null> */
    private array $channels = [];

    /** @var array<string, list<StockCheck>> */
    private array $checks = [];

    /**
     * @param list<DiscountRule>|null $rules
     */
    public function __construct(
        public readonly string $service,
        public readonly ServiceConnection $connection,
        public readonly ExternalItemResolver $resolver,
        public readonly bool $needsEvent,
        public readonly ?array $rules,
        public readonly string $discountLabel,
        private readonly EventRepository $events,
        private readonly OrderChannel $orderChannel,
        private readonly StockKeeper $stock,
    ) {
    }

    public function eventCovering(\DateTimeImmutable $placedAt): ?Event
    {
        $day = BusinessTime::day($placedAt);
        if (!\array_key_exists($day, $this->eventsByDay)) {
            $this->eventsByDay[$day] = $this->events->findCovering($placedAt);
        }

        return $this->eventsByDay[$day];
    }

    public function channelAt(?Event $event): ?SalesChannel
    {
        return $this->channels[$this->rememberedAt($event)];
    }

    /**
     * @return list<StockCheck>
     */
    public function checksAt(?Event $event): array
    {
        return $this->checks[$this->rememberedAt($event)];
    }

    private function rememberedAt(?Event $event): string
    {
        $key = (string) $event?->id();
        if (!\array_key_exists($key, $this->channels)) {
            $this->channels[$key] = $this->orderChannel->of($this->service, $event);
            $this->checks[$key] = $this->stock->checksAt($event);
        }

        return $key;
    }
}
