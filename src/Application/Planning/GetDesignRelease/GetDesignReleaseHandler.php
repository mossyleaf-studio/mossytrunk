<?php

declare(strict_types=1);

namespace App\Application\Planning\GetDesignRelease;

use App\Application\Planning\PlanningCalendar;
use App\Application\Planning\ReleaseView;
use App\Domain\Design\DesignRepository;
use App\Domain\Planning\ReleaseRepository;
use Symfony\Component\Uid\Ulid;

final readonly class GetDesignReleaseHandler
{
    public function __construct(
        private DesignRepository $designs,
        private ReleaseRepository $releases,
        private PlanningCalendar $calendar,
    ) {
    }

    public function __invoke(string $designId): DesignReleaseView
    {
        $design = $this->designs->get(Ulid::fromString($designId));
        $own = $this->releases->ofDesign($design);
        $collection = $design->collection();
        $release = $own ?? (null === $collection ? null : $this->releases->ofCollection($collection));

        return new DesignReleaseView(
            null === $release ? null : ReleaseView::of($release, $this->calendar->today()),
            null === $own && null !== $release,
        );
    }
}
