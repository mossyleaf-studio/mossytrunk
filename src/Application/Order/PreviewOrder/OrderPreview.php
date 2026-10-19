<?php

declare(strict_types=1);

namespace App\Application\Order\PreviewOrder;

final readonly class OrderPreview
{
    /**
     * @param list<array{label: string, amount: int, ruleId: ?string, manual?: array{kind: string, value: int}}> $discounts
     */
    public function __construct(
        public int $subtotal,
        public array $discounts,
        public int $discountTotal,
        public int $total,
    ) {
    }
}
