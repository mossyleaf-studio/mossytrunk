<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Planning\Restock;
use App\Domain\Planning\RestockRepository;
use App\Domain\Purchasing\SupplierOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineRestockRepository implements RestockRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function add(Restock $restock): void
    {
        $this->entityManager->persist($restock);
    }

    public function remove(Restock $restock): void
    {
        $this->entityManager->remove($restock);
    }

    public function get(Ulid $id): Restock
    {
        return $this->scope->get(Restock::class, $id, 'restock');
    }

    public function all(): array
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('r', 's', 'e', 'o')->from(Restock::class, 'r'), 'r')
            ->join('r.supplier', 's')
            ->leftJoin('r.event', 'e')
            ->leftJoin('r.supplierOrder', 'o')
            ->getQuery()
            ->getResult();
    }

    public function linkedTo(SupplierOrder $order): array
    {
        return $this->scope->findBy(Restock::class, ['supplierOrder' => $order]);
    }
}
