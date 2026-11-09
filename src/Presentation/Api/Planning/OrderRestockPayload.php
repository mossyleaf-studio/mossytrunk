<?php

declare(strict_types=1);

namespace App\Presentation\Api\Planning;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OrderRestockPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'supplier_order.invalid')]
        #[Assert\Ulid(message: 'supplier_order.invalid')]
        public string $supplierOrderId = '',
    ) {
    }
}
