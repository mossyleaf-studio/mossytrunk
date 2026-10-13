<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Discount\DiscountRule;
use App\Domain\Discount\DiscountRuleRepository;
use App\Domain\Discount\ProductTarget;
use App\Domain\Discount\TypeTarget;
use App\Domain\Product\Product;
use App\Domain\Product\ProductType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineDiscountRuleRepository implements DiscountRuleRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function add(DiscountRule $rule): void
    {
        $this->entityManager->persist($rule);
    }

    public function remove(DiscountRule $rule): void
    {
        $this->entityManager->remove($rule);
    }

    public function get(Ulid $id): DiscountRule
    {
        return $this->scope->get(DiscountRule::class, $id, 'discount');
    }

    public function all(): array
    {
        $rules = $this->scope->restrict($this->entityManager->createQueryBuilder()->select('r', 'c', 't', 'tc')->from(DiscountRule::class, 'r'), 'r')
            ->leftJoin('r.conditions', 'c')
            ->leftJoin('c.targets', 't')
            ->leftJoin('t.collection', 'tc')
            ->orderBy('r.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        $this->loadTargetedProducts();
        $this->loadTargetedTypes();

        return $rules;
    }

    private function loadTargetedProducts(): void
    {
        $this->scope->restrict($this->entityManager->createQueryBuilder()->select('p', 'pt')->from(Product::class, 'p'), 'p')
            ->join('p.type', 'pt')
            ->andWhere('p.id IN (SELECT IDENTITY(target.product) FROM '.ProductTarget::class.' target)')
            ->getQuery()
            ->getResult();
    }

    private function loadTargetedTypes(): void
    {
        $this->scope->restrict($this->entityManager->createQueryBuilder()->select('t')->from(ProductType::class, 't'), 't')
            ->andWhere('t.id IN (SELECT IDENTITY(target.type) FROM '.TypeTarget::class.' target)')
            ->getQuery()
            ->getResult();
    }
}
