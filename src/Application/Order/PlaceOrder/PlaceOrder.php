<?php

declare(strict_types=1);

namespace App\Application\Order\PlaceOrder;

use App\Application\Order\RequestedLine;

final readonly class PlaceOrder
{
    /**
     * @param list<RequestedLine> $lines
     * @param list<string>        $discountRuleIds
     */
    public function __construct(
        public \DateTimeImmutable $placedAt,
        public array $lines,
        public array $discountRuleIds = [],
        public ?string $note = null,
        public ?string $channelId = null,
        public ?string $eventId = null,
    ) {
    }
}
