<?php

declare(strict_types=1);

namespace App\Domain\Discount\Exception;

final class NotAManualDiscount extends InvalidDiscountRule
{
    public function __construct(string $name)
    {
        parent::__construct('discount.not_manual', ['name' => $name]);
    }
}
