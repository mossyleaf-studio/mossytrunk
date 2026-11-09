<?php

declare(strict_types=1);

namespace App\Application\Purchasing\CreateSupplier;

final readonly class CreateSupplier
{
    public function __construct(
        public string $name,
        public ?string $contact = null,
        public ?string $notes = null,
        public ?int $leadDays = null,
    ) {
    }
}
