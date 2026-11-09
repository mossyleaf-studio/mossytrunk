<?php

declare(strict_types=1);

namespace App\Presentation\Api\Purchasing;

use App\Application\Purchasing\CreateSupplier\CreateSupplier;
use App\Application\Purchasing\UpdateSupplier\UpdateSupplier;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SupplierPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'supplier.name.required')]
        #[Assert\Length(max: 255)]
        public string $name = '',
        #[Assert\Length(max: 255)]
        public ?string $contact = null,
        public ?string $notes = null,
        public ?int $leadDays = null,
    ) {
    }

    public function toCreate(): CreateSupplier
    {
        return new CreateSupplier($this->name, $this->contact, $this->notes, $this->leadDays);
    }

    public function toUpdate(string $supplierId): UpdateSupplier
    {
        return new UpdateSupplier($supplierId, $this->name, $this->contact, $this->notes, $this->leadDays);
    }
}
