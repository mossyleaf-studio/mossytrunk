<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Design\Design;
use App\Domain\Design\DesignCollection;
use App\Domain\Event\Event;
use App\Domain\Planning\Release;
use App\Domain\Planning\ReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineReleaseRepository implements ReleaseRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function add(Release $release): void
    {
        $this->entityManager->persist($release);
    }

    public function remove(Release $release): void
    {
        $this->entityManager->remove($release);
    }

    public function get(Ulid $id): Release
    {
        return $this->scope->get(Release::class, $id, 'release');
    }

    public function ofCollection(DesignCollection $collection): ?Release
    {
        return $this->scope->findOneBy(Release::class, ['collection' => $collection]);
    }

    public function ofDesign(Design $design): ?Release
    {
        return $this->scope->findOneBy(Release::class, ['design' => $design]);
    }

    public function all(): array
    {
        return $this->query()->getQuery()->getResult();
    }

    public function ofEvent(Event $event): array
    {
        return $this->query()->andWhere('r.event = :event')->setParameter('event', $event->id(), UlidType::NAME)->orderBy('r.id')->getQuery()->getResult();
    }

    private function query(): QueryBuilder
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('r', 'c', 'd', 'e')->from(Release::class, 'r'), 'r')
            ->leftJoin('r.collection', 'c')
            ->leftJoin('r.design', 'd')
            ->leftJoin('r.event', 'e');
    }
}
