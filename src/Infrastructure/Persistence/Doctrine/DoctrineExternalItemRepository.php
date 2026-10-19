<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Integration\ExternalItem;
use App\Domain\Integration\ExternalItemComponent;
use App\Domain\Integration\ExternalItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineExternalItemRepository implements ExternalItemRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function add(ExternalItem $item): void
    {
        $this->entityManager->persist($item);
    }

    public function get(Ulid $id): ExternalItem
    {
        return $this->scope->get(ExternalItem::class, $id, 'external_item');
    }

    public function ofService(string $service): array
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('item', 'component')->from(ExternalItem::class, 'item'), 'item')
            ->leftJoin('item.components', 'component')
            ->andWhere('item.service = :service')
            ->setParameter('service', $service)
            ->orderBy('item.seenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function linkedTo(Ulid $productId): array
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('item', 'component')->from(ExternalItem::class, 'item'), 'item')
            ->leftJoin('item.components', 'component')
            ->andWhere('item.id IN (SELECT IDENTITY(linked.item) FROM '.ExternalItemComponent::class.' linked WHERE linked.productId = :product)')
            ->setParameter('product', $productId, UlidType::NAME)
            ->getQuery()
            ->getResult();
    }

    public function unlinkedCount(string $service): int
    {
        return (int) $this->scope->restrict($this->entityManager->createQueryBuilder()->select('COUNT(item.id)')->from(ExternalItem::class, 'item'), 'item')
            ->andWhere('item.service = :service')
            ->andWhere('item.components IS EMPTY')
            ->setParameter('service', $service)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
