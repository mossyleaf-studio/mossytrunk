<?php

declare(strict_types=1);

namespace App\Application\Planning\PlanDesignRelease;

use App\Application\Planning\ReleasePlanning;
use App\Application\Planning\TargetEvent;
use App\Domain\Design\DesignRepository;
use App\Domain\Planning\Exception\ReleaseAlreadyPlanned;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class PlanDesignReleaseHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private DesignRepository $designs,
        private ReleasePlanning $planning,
        private TargetEvent $targetEvent,
    ) {
    }

    public function __invoke(PlanDesignRelease $command): Ulid
    {
        $design = $this->designs->get(Ulid::fromString($command->designId));
        if (null !== $this->releases->ofDesign($design)) {
            throw new ReleaseAlreadyPlanned($design->name());
        }

        return $this->planning->keep(Release::ofDesign($design, $command->day, $this->targetEvent->of($command->eventId), $command->steps));
    }
}
