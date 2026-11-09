<?php

declare(strict_types=1);

namespace App\Application\Planning\TickReleaseStep;

use App\Application\Transaction;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class TickReleaseStepHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(string $releaseId, string $step, bool $done): void
    {
        $this->releases->get(Ulid::fromString($releaseId))->tick($step, $done);
        $this->transaction->commit();
    }
}
