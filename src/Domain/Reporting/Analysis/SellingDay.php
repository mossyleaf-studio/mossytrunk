<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

final readonly class SellingDay
{
    public function __construct(
        public string $day,
        public int $orders,
        public ?SoldEvent $event,
    ) {
    }
}
