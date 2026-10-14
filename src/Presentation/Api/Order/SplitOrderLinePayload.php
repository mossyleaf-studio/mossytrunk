<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\RequestedLine;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SplitOrderLinePayload
{
    /**
     * @param list<OrderLinePayload> $lines
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'products.atLeastOne')]
        #[Assert\Valid]
        public array $lines = [],
    ) {
    }

    /**
     * @return list<RequestedLine>
     */
    public function requestedLines(): array
    {
        return array_map(static fn (OrderLinePayload $line): RequestedLine => $line->toRequestedLine(), $this->lines);
    }
}
