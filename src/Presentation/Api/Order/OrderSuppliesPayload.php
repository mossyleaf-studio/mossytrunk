<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\AddOrderSupplies\AddOrderSupplies;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class OrderSuppliesPayload
{
    /**
     * @param list<string>            $orderIds
     * @param list<SupplyLinePayload> $supplies
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'orders.selectAtLeastOne')]
        #[Assert\All([new Assert\Ulid()])]
        public array $orderIds = [],
        #[Assert\Count(min: 1, minMessage: 'supply.required')]
        #[Assert\Valid]
        public array $supplies = [],
    ) {
    }

    public function toCommand(): AddOrderSupplies
    {
        return new AddOrderSupplies($this->orderIds, array_map(static fn (SupplyLinePayload $line) => $line->toLine(), $this->supplies));
    }
}
