<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Shared\DateRange;
use Symfony\Component\Uid\Ulid;

interface OrderRepository
{
    public function add(Order $order): void;

    public function remove(Order $order): void;

    public function get(Ulid $id): Order;

    /**
     * @param list<Ulid> $ids
     *
     * @return list<Order>
     */
    public function getMany(array $ids): array;

    /**
     * @return list<Order>
     */
    public function salesWithin(DateRange $period): array;

    /**
     * @return list<Order>
     */
    public function mergeCandidatesOf(Order $order): array;

    /**
     * @return list<Order>
     */
    public function selling(Ulid $productId): array;

    public function sells(Ulid $productId): bool;

    /**
     * @return list<Order>
     */
    public function using(Ulid $supplyId): array;

    /**
     * @param list<string> $externalIds
     *
     * @return list<string>
     */
    public function importedExternalIds(string $source, array $externalIds): array;

    /**
     * @return list<Order>
     */
    public function awaitingSaleFees(string $source): array;

    /**
     * @param list<string> $externalIds
     *
     * @return list<Order>
     */
    public function importedAsOneOf(string $source, array $externalIds): array;

    public function countOutside(Ulid $eventId, DateRange $period): int;

    public function countWithoutEventOn(Ulid $channelId): int;
}
