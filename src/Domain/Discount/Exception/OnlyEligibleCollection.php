<?php

declare(strict_types=1);

namespace App\Domain\Discount\Exception;

final class OnlyEligibleCollection extends InvalidDiscountRule
{
    public function __construct(string $ruleName, string $collectionName)
    {
        parent::__construct('discount.only_eligible_collection', ['rule' => $ruleName, 'collection' => $collectionName]);
    }
}
