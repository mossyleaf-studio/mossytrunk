<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Domain\Order\PaymentMethod;
use App\Domain\Shared\Money;
use App\Infrastructure\Connector\Etsy\EtsyPayloadMapper;
use PHPUnit\Framework\TestCase;

final class EtsyPayloadMapperTest extends TestCase
{
    public function testAReceiptBecomesASaleChargedTheListedPricesMinusTheDiscount(): void
    {
        $sale = (new EtsyPayloadMapper())->sale([
            'receipt_id' => 42,
            'create_timestamp' => 1_893_456_000,
            'discount_amt' => ['amount' => 150, 'divisor' => 100],
            'total_shipping_cost' => ['amount' => 4_90, 'divisor' => 100],
            'transactions' => [[
                'listing_id' => 7,
                'title' => ' T-shirt Lichen ',
                'sku' => 'TSH-LICHEN',
                'quantity' => 2,
                'price' => ['amount' => 2_500, 'divisor' => 100],
                'variations' => [['formatted_name' => 'Taille', 'formatted_value' => 'M'], ['formatted_name' => 'Couleur', 'formatted_value' => 'Vert']],
            ]],
        ], Money::cents(351));

        self::assertSame(['42', 'ETSY-42'], [$sale->id, $sale->reference]);
        self::assertSame('2030-01-01T00:00:00+00:00', $sale->placedAt->format(\DATE_ATOM));
        self::assertSame([4_850, 490], [$sale->charged->amount(), $sale->shipping->amount()]);
        self::assertSame(PaymentMethod::Card, $sale->paymentMethod);
        self::assertSame(351, $sale->fee?->amount());
        $line = $sale->lines[0];
        self::assertSame(['7', 'T-shirt Lichen', 'TSH-LICHEN', 'M / Vert', 2, 2_500], [$line->externalRef, $line->name, $line->sku, $line->variant, $line->quantity, $line->unitPrice->amount()]);
    }
}
