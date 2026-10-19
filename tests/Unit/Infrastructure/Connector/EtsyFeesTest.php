<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Infrastructure\Connector\Etsy\EtsyFees;
use PHPUnit\Framework\TestCase;

final class EtsyFeesTest extends TestCase
{
    private const array RECEIPTS = [
        ['receipt_id' => 1, 'transactions' => [['transaction_id' => 11], ['transaction_id' => 12]]],
        ['receipt_id' => 2, 'transactions' => [['transaction_id' => 21]]],
    ];

    public function testAReceiptCostsEveryFeeOfItsLinesShippingAndPaymentWithTheirVat(): void
    {
        $fees = new EtsyFees(self::RECEIPTS, [
            ['entry_id' => 1, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '11', 'amount' => -52],
            ['entry_id' => 2, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '12', 'amount' => -117],
            ['entry_id' => 3, 'ledger_type' => 'shipping_transaction', 'reference_type' => 'receipt', 'reference_id' => '1', 'amount' => -16],
            ['entry_id' => 4, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => '0', 'parent_entry_id' => 1, 'amount' => -10],
            ['entry_id' => 5, 'ledger_type' => 'PAYMENT_GROSS', 'reference_type' => 'payment', 'reference_id' => '91', 'amount' => 3_150],
            ['entry_id' => 6, 'ledger_type' => 'PAYMENT_PROCESSING_FEE', 'reference_type' => 'payment', 'reference_id' => '91', 'amount' => -156],
            ['entry_id' => 7, 'ledger_type' => 'sales_tax', 'reference_type' => 'receipt', 'reference_id' => '1', 'amount' => -300],
            ['entry_id' => 8, 'ledger_type' => 'DISBURSE2', 'reference_type' => 'disbursement', 'reference_id' => '1', 'amount' => -2_000],
            ['entry_id' => 9, 'ledger_type' => 'listing', 'reference_type' => 'listing', 'reference_id' => '11', 'amount' => -20],
        ], [['payment_id' => 91, 'receipt_id' => 1]]);

        self::assertSame(351, $fees->of('1')?->amount());
    }

    public function testTheFeeIsUnknownUntilEtsyPostsThePayment(): void
    {
        $fees = new EtsyFees(self::RECEIPTS, [
            ['entry_id' => 1, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '21', 'amount' => -26],
        ], []);

        self::assertNull($fees->of('2'));
    }

    public function testRefundedFeesComeOff(): void
    {
        $fees = new EtsyFees(self::RECEIPTS, [
            ['entry_id' => 1, 'ledger_type' => 'PAYMENT_PROCESSING_FEE', 'reference_type' => 'payment', 'reference_id' => '92', 'amount' => -50],
            ['entry_id' => 2, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '21', 'amount' => -26],
            ['entry_id' => 3, 'ledger_type' => 'transaction_refund', 'reference_type' => 'transaction', 'reference_id' => '21', 'amount' => 26],
            ['entry_id' => 4, 'ledger_type' => 'REFUND_GROSS', 'reference_type' => 'payment', 'reference_id' => '92', 'amount' => -1_000],
        ], [['payment_id' => 92, 'receipt_id' => 2]]);

        self::assertSame(50, $fees->of('2')?->amount());
    }
}
