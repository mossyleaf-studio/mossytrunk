<?php

declare(strict_types=1);

namespace App\Application\Order\SplitOrderLine;

use App\Application\Order\RequestedLine;

final readonly class SplitOrderLine
{
    /**
     * @param list<RequestedLine> $lines
     */
    public function __construct(
        public string $orderId,
        public string $lineId,
        public array $lines,
    ) {
    }
}
