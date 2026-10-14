<?php

declare(strict_types=1);

namespace App\Domain\Order\Exception;

final class DiscountAlreadyGranted extends InvalidOrder
{
    public function __construct(string $name, string $reference)
    {
        parent::__construct('order.discount_already_granted', ['name' => $name, 'reference' => $reference]);
    }
}
