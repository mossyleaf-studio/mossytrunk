<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Order\ImportedSale;
use App\Domain\Order\Order;
use App\Domain\Order\OrderLine;
use App\Domain\Order\OrderRepository;
use App\Domain\Order\OrderSupply;
use App\Domain\Shared\DateRange;
use App\Domain\Shared\Exception\NotFound;
use App\Infrastructure\Persistence\Doctrine\Reporting\SalePeriodBounds;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineOrderRepository implements OrderRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkspaceScope $scope,
    ) {
    }

    public function add(Order $order): void
    {
        $this->entityManager->persist($order);
    }

    public function remove(Order $order): void
    {
        $this->entityManager->remove($order);
    }

    public function get(Ulid $id): Order
    {
        return $this->scope->get(Order::class, $id, 'order');
    }

    public function getMany(array $ids): array
    {
        $query = $this->scope->restrict($this->entityManager->createQueryBuilder()->select('o')->from(Order::class, 'o'), 'o')
            ->andWhere('o.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Ulid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery();

        $found = [];
        foreach ($this->loaded($query, 'lines', 'supplies', 'importedSales') as $order) {
            $found[(string) $order->id()] = $order;
        }

        return array_map(static fn (Ulid $id): Order => $found[(string) $id] ?? throw new NotFound('order', (string) $id), $ids);
    }

    public function salesWithin(DateRange $period): array
    {
        [$from, $until] = SalePeriodBounds::of($period);

        return $this->loaded($this->orders(null)
            ->andWhere('o.refundedAt IS NULL')
            ->andWhere('o.placedAt >= :from AND o.placedAt < :until')
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('until', $until, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery(), 'lines', 'importedSales');
    }

    public function mergeCandidatesOf(Order $order): array
    {
        $query = $this->orders($order->event()?->id())
            ->andWhere('o.refundedAt IS NULL')
            ->andWhere('o.source = :source')
            ->andWhere('o.id != :order')
            ->setParameter('source', $order->source())
            ->setParameter('order', $order->id(), UlidType::NAME);

        if (null === $order->event()) {
            $query->andWhere('o.event IS NULL');
        }

        return $this->loaded($query->getQuery(), 'lines', 'importedSales');
    }

    public function selling(Ulid $productId): array
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('o', 'l', 'e')->from(Order::class, 'o'), 'o')
            ->join('o.lines', 'l')
            ->leftJoin('o.event', 'e')
            ->andWhere('o.id IN (SELECT IDENTITY(s.order) FROM '.OrderLine::class.' s WHERE s.productId = :product)')
            ->setParameter('product', $productId, UlidType::NAME)
            ->getQuery()
            ->getResult();
    }

    public function sells(Ulid $productId): bool
    {
        return null !== $this->scope->restrict($this->entityManager->createQueryBuilder()->select('o.id')->from(Order::class, 'o'), 'o')
            ->join('o.lines', 'l')
            ->andWhere('l.productId = :product')
            ->setParameter('product', $productId, UlidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function using(Ulid $supplyId): array
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('o', 's', 'e')->from(Order::class, 'o'), 'o')
            ->join('o.supplies', 's')
            ->leftJoin('o.event', 'e')
            ->andWhere('o.id IN (SELECT IDENTITY(u.order) FROM '.OrderSupply::class.' u WHERE u.productId = :supply)')
            ->setParameter('supply', $supplyId, UlidType::NAME)
            ->getQuery()
            ->getResult();
    }

    public function importedExternalIds(string $source, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        $ids = $this->scope->restrict($this->entityManager->createQueryBuilder()->select('s.externalId')->from(ImportedSale::class, 's'), 's')
            ->andWhere('s.externalId IN (:ids)')
            ->andWhere('s.source = :source')
            ->setParameter('source', $source)
            ->setParameter('ids', $externalIds, ArrayParameterType::STRING)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): string => \is_scalar($id) ? (string) $id : '', $ids));
    }

    public function awaitingSaleFees(string $source): array
    {
        return $this->scope->restrict($this->entityManager->createQueryBuilder()->select('o', 'i')->from(Order::class, 'o'), 'o')
            ->join('o.importedSales', 'i')
            ->andWhere('o.id IN (SELECT IDENTITY(s.order) FROM '.ImportedSale::class.' s WHERE s.source = :source AND s.fee IS NULL)')
            ->setParameter('source', $source)
            ->getQuery()
            ->getResult();
    }

    public function importedAsOneOf(string $source, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        return $this->loaded($this->orders(null)
            ->andWhere('o.id IN (SELECT IDENTITY(s.order) FROM '.ImportedSale::class.' s WHERE s.source = :source AND s.externalId IN (:ids))')
            ->setParameter('source', $source)
            ->setParameter('ids', $externalIds, ArrayParameterType::STRING)
            ->getQuery(), 'lines', 'importedSales');
    }

    public function countWithoutEventOn(Ulid $channelId): int
    {
        return (int) $this->scope->restrict($this->entityManager->createQueryBuilder()->select('COUNT(o.id)')->from(Order::class, 'o'), 'o')
            ->andWhere('o.channel = :channel')
            ->andWhere('o.event IS NULL')
            ->setParameter('channel', $channelId, UlidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOutside(Ulid $eventId, DateRange $period): int
    {
        [$from, $until] = SalePeriodBounds::of($period);

        return (int) $this->scope->restrict($this->entityManager->createQueryBuilder()->select('COUNT(o.id)')->from(Order::class, 'o'), 'o')
            ->andWhere('o.event = :event')
            ->andWhere('o.placedAt < :from OR o.placedAt >= :until')
            ->setParameter('event', $eventId, UlidType::NAME)
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('until', $until, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function orders(?Ulid $eventId): QueryBuilder
    {
        $query = $this->scope->restrict($this->entityManager->createQueryBuilder()->select('o', 'e')->from(Order::class, 'o'), 'o')
            ->leftJoin('o.event', 'e')
            ->orderBy('o.placedAt', 'DESC');

        if (null !== $eventId) {
            $query->andWhere('e.id = :event')->setParameter('event', $eventId, UlidType::NAME);
        }

        return $query;
    }

    /**
     * @param Query<null, Order> $query
     *
     * @return list<Order>
     */
    private function loaded(Query $query, string ...$collections): array
    {
        foreach ($collections as $collection) {
            $query->setFetchMode(Order::class, $collection, ClassMetadata::FETCH_EAGER);
        }

        return $query->getResult();
    }
}
