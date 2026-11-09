<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use App\Domain\Purchasing\Supplier;

final readonly class SupplierView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $contact,
        public ?string $notes,
        public ?int $leadDays,
    ) {
    }

    public static function of(Supplier $supplier): self
    {
        return new self((string) $supplier->id(), $supplier->name(), $supplier->contact(), $supplier->notes(), $supplier->leadDays());
    }
}
