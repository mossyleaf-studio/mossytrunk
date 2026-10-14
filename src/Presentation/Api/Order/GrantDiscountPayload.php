<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GrantDiscountPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'discount.required')]
        #[Assert\Ulid(message: 'discount.required')]
        public string $ruleId = '',
    ) {
    }
}
