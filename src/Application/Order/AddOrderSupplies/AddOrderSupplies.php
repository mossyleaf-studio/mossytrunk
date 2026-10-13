<?php

declare(strict_types=1);

namespace App\Application\Order\AddOrderSupplies;

use App\Application\Order\RequestedLine;

final readonly class AddOrderSupplies
{
    /**
     * @param list<string>        $orderIds
     * @param list<RequestedLine> $supplies
     */
    public function __construct(
        public array $orderIds,
        public array $supplies,
    ) {
    }
}
