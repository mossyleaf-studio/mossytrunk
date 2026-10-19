<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\ConfigureConnection\ConnectionSettings;
use App\Application\Integration\ExternalLine;
use App\Application\Integration\ExternalSale;
use App\Domain\Integration\SalesContext;
use App\Domain\Integration\UnknownItems;
use App\Domain\Order\PaymentMethod;
use App\Domain\Shared\Money;

final class ExternalSales
{
    /**
     * @param list<ExternalLine> $lines
     */
    public static function sumUp(string $code, \DateTimeImmutable $placedAt, Money $charged, array $lines, ?PaymentMethod $paymentMethod = null, ?Money $fee = null): ExternalSale
    {
        return new ExternalSale($code, $code, $placedAt, $lines, $charged, Money::zero(), $paymentMethod, $fee);
    }

    /**
     * @param list<ExternalLine> $lines
     */
    public static function etsy(string $receiptId, \DateTimeImmutable $placedAt, array $lines, int $discount = 0, int $shipping = 0, ?\DateTimeImmutable $cancelledAt = null): ExternalSale
    {
        $listed = Money::sum(array_map(static fn (ExternalLine $line): Money => $line->unitPrice->multiply($line->quantity), $lines));

        return new ExternalSale($receiptId, 'ETSY-'.$receiptId, $placedAt, $lines, $listed->subtract(Money::cents($discount)), Money::cents($shipping), PaymentMethod::Card, cancelledAt: $cancelledAt);
    }

    public static function line(string $name, Money $unitPrice, int $quantity = 1, ?string $category = null, ?string $variant = null): ExternalLine
    {
        return new ExternalLine(mb_strtolower(trim($name)), $name, $unitPrice, $quantity, $variant, $category);
    }

    public static function listing(string $listingId, string $title, int $unitPrice, int $quantity = 1, ?string $variation = null, ?string $sku = null): ExternalLine
    {
        return new ExternalLine($listingId, $title, Money::cents($unitPrice), $quantity, $variation, sku: $sku);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function connect(AddConnectionHandler $add, string $service, array $fields, ?SalesContext $salesContext = null, ?UnknownItems $unknownItems = null): void
    {
        $add(new ConnectionSettings($service, $fields, $salesContext, $unknownItems));
    }
}
