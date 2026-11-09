<?php

declare(strict_types=1);

namespace App\Application\Purchasing\UpdateSupplier;

use App\Application\Purchasing\SupplierAvailability;
use App\Application\Transaction;
use App\Domain\Purchasing\Supplier;
use App\Domain\Purchasing\SupplierRepository;
use Symfony\Component\Uid\Ulid;

final readonly class UpdateSupplierHandler
{
    public function __construct(
        private SupplierRepository $suppliers,
        private SupplierAvailability $availability,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(UpdateSupplier $command): Supplier
    {
        $supplier = $this->suppliers->get(Ulid::fromString($command->supplierId));
        $this->availability->assertNameFree($command->name, $supplier);

        $supplier->describe($command->name, $command->contact, $command->notes, $command->leadDays);
        $this->transaction->commit();

        return $supplier;
    }
}
