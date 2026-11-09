<?php

declare(strict_types=1);

namespace App\Application\Purchasing\CreateSupplier;

use App\Application\Purchasing\SupplierAvailability;
use App\Application\Transaction;
use App\Application\WorkspaceContext;
use App\Domain\Purchasing\Supplier;
use App\Domain\Purchasing\SupplierRepository;

final readonly class CreateSupplierHandler
{
    public function __construct(
        private SupplierRepository $suppliers,
        private SupplierAvailability $availability,
        private WorkspaceContext $workspace,
        private Transaction $transaction,
    ) {
    }

    public function __invoke(CreateSupplier $command): Supplier
    {
        $this->availability->assertNameFree($command->name);

        $supplier = Supplier::create($this->workspace->current(), $command->name, $command->contact, $command->notes, $command->leadDays);
        $this->suppliers->add($supplier);
        $this->transaction->commit();

        return $supplier;
    }
}
