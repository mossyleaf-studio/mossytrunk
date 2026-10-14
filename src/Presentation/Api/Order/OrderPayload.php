<?php

declare(strict_types=1);

namespace App\Presentation\Api\Order;

use App\Application\Order\RequestedLine;
use App\Domain\Shared\BusinessTime;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class OrderPayload
{
    /**
     * @param list<OrderLinePayload> $lines
     * @param list<string>           $discountRuleIds
     */
    public function __construct(
        #[Assert\NotBlank(message: 'date.required')]
        #[Assert\Regex('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', message: 'date.invalid')]
        public string $placedAt = '',
        #[Assert\Count(min: 1, minMessage: 'products.atLeastOne')]
        #[Assert\Valid]
        public array $lines = [],
        #[Assert\All([new Assert\Ulid()])]
        public array $discountRuleIds = [],
    ) {
    }

    public function placedAt(): \DateTimeImmutable
    {
        return BusinessTime::at($this->placedAt);
    }

    /**
     * @return list<RequestedLine>
     */
    public function requestedLines(): array
    {
        return array_map(static fn (OrderLinePayload $line): RequestedLine => $line->toRequestedLine(), $this->lines);
    }
}
