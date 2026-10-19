<?php

declare(strict_types=1);

namespace App\Application\Integration;

use App\Domain\Order\PaymentMethod;
use App\Domain\Shared\Money;

final readonly class ExternalSale
{
    /**
     * @param list<ExternalLine> $lines
     */
    public function __construct(
        public string $id,
        public string $reference,
        public \DateTimeImmutable $placedAt,
        public array $lines,
        public Money $charged,
        public Money $shipping,
        public ?PaymentMethod $paymentMethod = null,
        public ?Money $fee = null,
        public ?\DateTimeImmutable $cancelledAt = null,
    ) {
    }
}
