<?php

declare(strict_types=1);

namespace App\Application\Planning\PlanRestock;

use App\Application\Planning\TargetEvent;
use App\Application\Transaction;
use App\Domain\Planning\Exception\PastRestockTarget;
use App\Domain\Planning\Restock;
use App\Domain\Planning\RestockRepository;
use App\Domain\Purchasing\SupplierRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

final readonly class PlanRestockHandler
{
    public function __construct(
        private RestockRepository $restocks,
        private SupplierRepository $suppliers,
        private TargetEvent $targetEvent,
        private ClockInterface $clock,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(PlanRestock $command): Ulid
    {
        $restock = Restock::plan(
            $this->suppliers->get(Ulid::fromString($command->supplierId)),
            $command->note,
            $command->day,
            $this->targetEvent->of($command->eventId),
            $command->leadDays,
        );
        if ($restock->isPastOn($this->clock->now())) {
            throw new PastRestockTarget();
        }
        $this->restocks->add($restock);
        $this->transaction->commit();

        return $restock->id();
    }
}
