<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Purchasing\SupplierOrder;
use Symfony\Component\Uid\Ulid;

interface RestockRepository
{
    public function add(Restock $restock): void;

    public function remove(Restock $restock): void;

    public function get(Ulid $id): Restock;

    /**
     * @return list<Restock>
     */
    public function all(): array;

    /**
     * @return list<Restock>
     */
    public function linkedTo(SupplierOrder $order): array;
}
