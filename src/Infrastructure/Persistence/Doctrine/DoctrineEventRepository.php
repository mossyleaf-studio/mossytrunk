<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Event\Event;
use App\Domain\Event\EventRepository;
use App\Domain\Shared\BusinessTime;
use App\Domain\Shared\DateRange;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineEventRepository implements EventRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function add(Event $event): void
    {
        $this->entityManager->persist($event);
    }

    public function get(Ulid $id): Event
    {
        return $this->scope->get(Event::class, $id, 'event');
    }

    public function findCovering(\DateTimeImmutable $moment): ?Event
    {
        $result = $this->scope->restrict($this->entityManager->createQueryBuilder()->select('e')->from(Event::class, 'e'), 'e')
            ->andWhere('e.period.start <= :day')
            ->andWhere('e.period.end >= :day')
            ->setParameter('day', BusinessTime::day($moment))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Event ? $result : null;
    }

    public function findOverlapping(DateRange $period, ?Ulid $except = null): ?Event
    {
        $query = $this->scope->restrict($this->entityManager->createQueryBuilder()->select('e')->from(Event::class, 'e'), 'e')
            ->andWhere('e.period.start <= :end')
            ->andWhere('e.period.end >= :start')
            ->setParameter('start', $period->start(), Types::DATE_IMMUTABLE)
            ->setParameter('end', $period->end(), Types::DATE_IMMUTABLE)
            ->setMaxResults(1);

        if (null !== $except) {
            $query->andWhere('e.id != :except')->setParameter('except', $except, UlidType::NAME);
        }

        $result = $query->getQuery()->getOneOrNullResult();

        return $result instanceof Event ? $result : null;
    }

    public function all(): array
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('e', 'x', 'd')->from(Event::class, 'e'), 'e')
            ->leftJoin('e.expenses', 'x')
            ->leftJoin('e.donations', 'd')
            ->orderBy('e.period.start', 'DESC')
            ->addOrderBy('x.createdAt', 'ASC')
            ->addOrderBy('x.id', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
