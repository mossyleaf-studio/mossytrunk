<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Planning\GetPlanning\PlanningView;
use App\Domain\Planning\Restock;
use App\Domain\Shared\BusinessTime;

/**
 * @phpstan-import-type RestockItem from PlanningView
 */
final readonly class RestockView
{
    /**
     * @param array{id: string, name: string}           $supplier
     * @param array{id: string, name: string}|null      $event
     * @param array{id: string, reference: string}|null $supplierOrder
     */
    public function __construct(
        public string $id,
        public array $supplier,
        public ?string $note,
        public string $day,
        public ?array $event,
        public int $leadDays,
        public string $orderBy,
        public bool $ordered,
        public ?string $orderedOn,
        public ?array $supplierOrder,
        public string $state,
        public bool $past,
    ) {
    }

    public static function of(Restock $restock, \DateTimeImmutable $today): self
    {
        $event = $restock->event();
        $orderedOn = $restock->orderedOn();
        $order = $restock->supplierOrder();

        return new self(
            (string) $restock->id(),
            ['id' => (string) $restock->supplier()->id(), 'name' => $restock->supplier()->name()],
            $restock->note(),
            BusinessTime::day($restock->targetDay()),
            null === $event ? null : ['id' => (string) $event->id(), 'name' => $event->name()],
            $restock->leadDays(),
            BusinessTime::day($restock->orderBy()),
            $restock->isOrdered(),
            null === $orderedOn ? null : BusinessTime::day($orderedOn),
            null === $order ? null : ['id' => (string) $order->id(), 'reference' => $order->reference()],
            $restock->stateOn($today)->value,
            $restock->isPastOn($today),
        );
    }

    /**
     * @return RestockItem
     */
    public function item(): array
    {
        return [
            'kind' => 'restock',
            'id' => $this->id,
            'supplier' => $this->supplier,
            'note' => $this->note,
            'day' => $this->day,
            'event' => $this->event,
            'leadDays' => $this->leadDays,
            'orderBy' => $this->orderBy,
            'ordered' => $this->ordered,
            'orderedOn' => $this->orderedOn,
            'supplierOrder' => $this->supplierOrder,
            'state' => $this->state,
            'past' => $this->past,
        ];
    }
}
