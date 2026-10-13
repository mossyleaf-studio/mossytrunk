<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\RequestedLine;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SupplyLinePayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'supply.required')]
        #[Assert\Ulid(message: 'supply.required')]
        public string $supplyId = '',
        public ?string $variant = null,
        #[Assert\Positive(message: 'quantity.atLeastOne')]
        public int $quantity = 1,
    ) {
    }

    public function toLine(): RequestedLine
    {
        return new RequestedLine($this->supplyId, null === $this->variant || '' === trim($this->variant) ? null : $this->variant, $this->quantity);
    }
}
