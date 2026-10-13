<?php

declare(strict_types=1);

namespace App\Application\Event\GetEvent;

use App\Application\Event\ExpenseShareRow;
use App\Domain\Event\Donation;
use App\Domain\Event\Event;
use App\Domain\Event\EventTiming;
use App\Domain\Event\ExpenseShare;
use App\Domain\Event\ExpenseShares;

final readonly class EventView
{
    /**
     * @param list<array{id: string, label: string, amount: int, fullAmount: int, sharedBy: int, sharedOverEvents: int|null, sharedUntil: string|null, own: bool, originId: string, originName: string}> $expenses
     * @param list<array{id: string, label: ?string, amount: int}>                                                                                                                                       $donations
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $location,
        public string $startDate,
        public string $endDate,
        public array $expenses,
        public int $expensesTotal,
        public string $timing,
        public array $donations,
        public int $donationsTotal,
    ) {
    }

    public static function fromEvent(Event $event, EventTiming $timing, ExpenseShares $shares): self
    {
        return new self(
            (string) $event->id(),
            $event->name(),
            $event->location(),
            $event->period()->start()->format('Y-m-d'),
            $event->period()->end()->format('Y-m-d'),
            array_map(static fn (ExpenseShare $share): array => ExpenseShareRow::of($share, $event), $shares->of($event)),
            $shares->totalOf($event)->amount(),
            $timing->value,
            array_map(static fn (Donation $donation): array => ['id' => (string) $donation->id(), 'label' => $donation->label(), 'amount' => $donation->amount()->amount()], $event->donations()),
            $event->donationsTotal()->amount(),
        );
    }
}
