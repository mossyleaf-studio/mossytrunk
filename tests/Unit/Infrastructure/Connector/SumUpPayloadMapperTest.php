<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Application\Integration\ExternalLine;
use App\Domain\Order\PaymentMethod;
use App\Infrastructure\Connector\SumUp\SumUpPayloadMapper;
use PHPUnit\Framework\TestCase;

final class SumUpPayloadMapperTest extends TestCase
{
    public function testMapsAmountsAndOptionalCategory(): void
    {
        $sale = (new SumUpPayloadMapper())->sale([
            'transaction_code' => 'T1',
            'timestamp' => '2030-03-14T10:00:00Z',
            'amount' => 19.5,
            'tip_amount' => 0.5,
            'products' => [
                ['name' => 'Forêt', 'description' => "  A4 \n", 'price_label' => 'Prix', 'price' => 15.0, 'price_with_vat' => 15.0, 'quantity' => 1, 'category' => ['name' => 'Print']],
                ['name' => 'Mousse', 'price' => 4.0, 'quantity' => 1, 'category_name' => 'Sticker'],
                ['name' => 'Libre', 'price' => 0.5],
            ],
        ]);

        self::assertSame(['T1', 'T1'], [$sale->id, $sale->reference]);
        self::assertSame(1_900, $sale->charged->amount());
        self::assertTrue($sale->shipping->isZero());
        self::assertSame(['Print', 'Sticker', null], array_map(static fn (ExternalLine $line): ?string => $line->category, $sale->lines));
        self::assertSame(['A4', null, null], array_map(static fn (ExternalLine $line): ?string => $line->variant, $sale->lines));
        self::assertSame(['forêt', 'mousse', 'libre'], array_map(static fn (ExternalLine $line): string => $line->externalRef, $sale->lines));
        self::assertSame(50, $sale->lines[2]->unitPrice->amount());
    }

    public function testKeypadAmountsHaveNoName(): void
    {
        $sale = (new SumUpPayloadMapper())->sale([
            'transaction_code' => 'T1',
            'timestamp' => '2030-03-14T10:00:00Z',
            'amount' => 12.0,
            'products' => [['name' => 'Custom amount', 'price' => 2.0, 'quantity' => 1], ['price' => 10.0, 'quantity' => 1]],
        ]);

        self::assertSame(['', ''], array_map(static fn (ExternalLine $line): string => $line->name, $sale->lines));
    }

    public function testCashPaymentsAreToldApartFromCardOnes(): void
    {
        $mapper = new SumUpPayloadMapper();
        $payment = static fn (?string $type): ?PaymentMethod => $mapper->sale(['transaction_code' => 'T', 'timestamp' => '2030-03-14T10:00:00Z', 'amount' => 1.0] + (null === $type ? [] : ['payment_type' => $type]))->paymentMethod;

        self::assertSame(PaymentMethod::Cash, $payment('CASH'));
        self::assertSame(PaymentMethod::Card, $payment('POS'));
        self::assertNull($payment(null));
    }

    public function testOnlinePaymentsAreToldApartFromTerminalOnes(): void
    {
        $mapper = new SumUpPayloadMapper();

        self::assertTrue($mapper->paidOnline(['payment_type' => 'ECOM']));
        self::assertTrue($mapper->paidOnline(['payment_type' => " ecom\n"]));
        self::assertFalse($mapper->paidOnline(['payment_type' => 'POS']));
        self::assertFalse($mapper->paidOnline(['payment_type' => 'CASH']));
        self::assertFalse($mapper->paidOnline(['payment_type' => 'BOLETO']), 'an unknown type stays a terminal sale');
        self::assertFalse($mapper->paidOnline(['payment_type' => null]));
        self::assertFalse($mapper->paidOnline([]));
    }

    public function testKeepsTheFeeSumUpTookOnItsPayoutsAndKnowsCashCostsNothing(): void
    {
        self::assertSame(16, self::fee(['payment_type' => 'POS', 'events' => [['type' => 'PAYOUT', 'amount' => 8.84, 'fee_amount' => 0.16]]]));
        self::assertSame(30, self::fee(['payment_type' => 'POS', 'events' => [
            ['type' => 'PAYOUT', 'fee_amount' => 0.175],
            ['type' => 'PAYOUT', 'fee_amount' => 0.12],
            ['type' => 'REFUND', 'fee_amount' => 0.5],
        ]]));
        self::assertNull(self::fee(['payment_type' => 'POS']), 'not paid out yet');
        self::assertNull(self::fee(['payment_type' => 'POS', 'events' => [['type' => 'REFUND', 'fee_amount' => 0.5]]]), 'not paid out yet');
        self::assertSame(0, self::fee(['payment_type' => 'CASH']));
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private static function fee(array $transaction): ?int
    {
        return (new SumUpPayloadMapper())->sale(['transaction_code' => 'T', 'timestamp' => '2030-03-14T10:00:00Z', 'amount' => 10.0] + $transaction)->fee?->amount();
    }
}
