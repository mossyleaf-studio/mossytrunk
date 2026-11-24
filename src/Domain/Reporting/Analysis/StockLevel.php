<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Analysis;

enum StockLevel: string
{
    case Out = 'out';
    case Low = 'low';
    case Ok = 'ok';

    public static function of(int $onHand, ?int $coverOrders, int $busiestEventOrders): self
    {
        return match (true) {
            $onHand <= 0 => self::Out,
            null !== $coverOrders && $coverOrders < $busiestEventOrders => self::Low,
            default => self::Ok,
        };
    }

    public function isShort(): bool
    {
        return self::Ok !== $this;
    }
}
