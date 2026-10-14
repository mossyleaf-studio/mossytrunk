<?php

declare(strict_types=1);

namespace App\Domain\Discount\Exception;

final class DiscountNotValidOn extends InvalidDiscountRule
{
    public function __construct(string $name, \DateTimeImmutable $date)
    {
        parent::__construct('discount.not_valid_on', ['name' => $name, 'date' => $date]);
    }
}
