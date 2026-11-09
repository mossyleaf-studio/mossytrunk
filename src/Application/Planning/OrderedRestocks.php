<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Domain\Planning\RestockRepository;
use App\Domain\Purchasing\SupplierOrder;

final readonly class OrderedRestocks
{
    public function __construct(private RestockRepository $restocks)
    {
    }

    public function follow(SupplierOrder $order): void
    {
        foreach ($this->restocks->linkedTo($order) as $restock) {
            $restock->followOrder();
        }
    }

    public function moveInto(SupplierOrder $absorbed, SupplierOrder $order): void
    {
        foreach ($this->restocks->linkedTo($absorbed) as $restock) {
            $restock->orderedThrough($order);
        }
    }
}
