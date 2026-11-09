<?php

declare(strict_types=1);

namespace App\Application\Planning\CancelRelease;

use App\Application\Transaction;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class CancelReleaseHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $releaseId): void
    {
        $this->releases->remove($this->releases->get(Ulid::fromString($releaseId)));
        $this->transaction->commit();
    }
}
