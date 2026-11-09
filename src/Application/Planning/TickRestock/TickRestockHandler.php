<?php

declare(strict_types=1);

namespace App\Application\Planning\TickRestock;

use App\Application\Transaction;
use App\Domain\Planning\RestockRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

final readonly class TickRestockHandler
{
    public function __construct(
        private RestockRepository $restocks,
        private ClockInterface $clock,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $restockId, bool $ordered): void
    {
        $restock = $this->restocks->get(Ulid::fromString($restockId));
        if (!$ordered) {
            $restock->markNotOrdered();
        } elseif (!$restock->isOrdered()) {
            $restock->markOrdered($this->clock->now());
        }
        $this->transaction->commit();
    }
}
