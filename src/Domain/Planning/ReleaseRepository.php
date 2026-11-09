<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Design\Design;
use App\Domain\Design\DesignCollection;
use App\Domain\Event\Event;
use Symfony\Component\Uid\Ulid;

interface ReleaseRepository
{
    public function add(Release $release): void;

    public function remove(Release $release): void;

    public function get(Ulid $id): Release;

    public function ofCollection(DesignCollection $collection): ?Release;

    public function ofDesign(Design $design): ?Release;

    /**
     * @return list<Release>
     */
    public function all(): array;

    /**
     * @return list<Release>
     */
    public function ofEvent(Event $event): array;
}
