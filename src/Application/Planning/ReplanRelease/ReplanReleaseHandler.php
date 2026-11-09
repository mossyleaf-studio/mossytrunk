<?php

declare(strict_types=1);

namespace App\Application\Planning\ReplanRelease;

use App\Application\Planning\TargetEvent;
use App\Application\Transaction;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class ReplanReleaseHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private TargetEvent $targetEvent,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(ReplanRelease $command): void
    {
        $release = $this->releases->get(Ulid::fromString($command->releaseId));
        $release->schedule($command->day, $this->targetEvent->of($command->eventId));
        $release->replan($command->steps);
        $this->transaction->commit();
    }
}
