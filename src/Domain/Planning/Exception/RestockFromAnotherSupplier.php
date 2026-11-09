<?php

declare(strict_types=1);

namespace App\Domain\Planning\Exception;

final class RestockFromAnotherSupplier extends InvalidPlanning
{
    public function __construct(string $supplier)
    {
        parent::__construct('planning.restock_from_another_supplier', ['supplier' => $supplier]);
    }
}
