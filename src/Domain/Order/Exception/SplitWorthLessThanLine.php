<?php

declare(strict_types=1);

namespace App\Domain\Order\Exception;

use App\Domain\Shared\Money;

final class SplitWorthLessThanLine extends InvalidOrder
{
    public function __construct(string $label, Money $worth, Money $paid)
    {
        parent::__construct('order.split_worth_less', ['label' => $label, 'worth' => $worth->amount() / 100, 'paid' => $paid->amount() / 100]);
    }
}
