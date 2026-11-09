<?php

declare(strict_types=1);

namespace App\Application\Planning\ReviseRestock;

use App\Application\Planning\TargetEvent;
use App\Application\Transaction;
use App\Domain\Planning\Exception\PastRestockTarget;
use App\Domain\Planning\RestockRepository;
use App\Domain\Purchasing\SupplierRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

final readonly class ReviseRestockHandler
{
    public function __construct(
        private RestockRepository $restocks,
        private SupplierRepository $suppliers,
        private TargetEvent $targetEvent,
        private ClockInterface $clock,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(ReviseRestock $command): void
    {
        $restock = $this->restocks->get(Ulid::fromString($command->restockId));
        $restock->revise(
            $this->suppliers->get(Ulid::fromString($command->supplierId)),
            $command->note,
            $command->day,
            $this->targetEvent->of($command->eventId),
            $command->leadDays,
        );
        if ($restock->isPastOn($this->clock->now())) {
            throw new PastRestockTarget();
        }
        $this->transaction->commit();
    }
}
