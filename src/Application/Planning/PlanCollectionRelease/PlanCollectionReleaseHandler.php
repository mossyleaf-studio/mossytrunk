<?php

declare(strict_types=1);

namespace App\Application\Planning\PlanCollectionRelease;

use App\Application\Planning\ReleasePlanning;
use App\Domain\Design\DesignCollectionRepository;
use App\Domain\Planning\Exception\ReleaseAlreadyPlanned;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class PlanCollectionReleaseHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private DesignCollectionRepository $collections,
        private ReleasePlanning $planning,
    ) {
    }

    public function __invoke(PlanCollectionRelease $command): Ulid
    {
        $collection = $this->collections->get(Ulid::fromString($command->collectionId));
        if (null !== $this->releases->ofCollection($collection)) {
            throw new ReleaseAlreadyPlanned($collection->name());
        }

        return $this->planning->keep(Release::ofCollection($collection, $command->day, $this->planning->event($command->eventId), $command->steps));
    }
}
