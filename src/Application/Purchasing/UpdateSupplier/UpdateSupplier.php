<?php

declare(strict_types=1);

namespace App\Application\Purchasing\UpdateSupplier;

final readonly class UpdateSupplier
{
    public function __construct(
        public string $supplierId,
        public string $name,
        public ?string $contact = null,
        public ?string $notes = null,
        public ?int $leadDays = null,
    ) {
    }
}
