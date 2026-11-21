<?php

declare(strict_types=1);

namespace App\Application\Order\SplitOrderLine;

use App\Application\Order\RequestedLine;

final readonly class SplitOrderLine
{
    /**
     * @param list<RequestedLine> $lines
     * @param list<string>        $alongWith
     */
    public function __construct(
        public string $orderId,
        public string $lineId,
        public array $lines,
        public array $alongWith = [],
    ) {
    }
}
