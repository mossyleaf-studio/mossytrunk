<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Transaction;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class ReleasePlanning
{
    public function __construct(
        private ReleaseRepository $releases,
        private Transaction $transaction,
    ) {
    }

    public function keep(Release $release): Ulid
    {
        $this->releases->add($release);
        $this->transaction->commit();

        return $release->id();
    }
}
