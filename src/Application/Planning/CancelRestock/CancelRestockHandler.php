<?php

declare(strict_types=1);

namespace App\Application\Planning\CancelRestock;

use App\Application\Transaction;
use App\Domain\Planning\RestockRepository;
use Symfony\Component\Uid\Ulid;

final readonly class CancelRestockHandler
{
    public function __construct(
        private RestockRepository $restocks,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $restockId): void
    {
        $this->restocks->remove($this->restocks->get(Ulid::fromString($restockId)));
        $this->transaction->commit();
    }
}
