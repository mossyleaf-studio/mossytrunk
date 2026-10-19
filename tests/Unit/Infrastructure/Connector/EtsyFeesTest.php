<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Infrastructure\Connector\Etsy\EtsyFees;
use PHPUnit\Framework\TestCase;

final class EtsyFeesTest extends TestCase
{
    private const array RECEIPTS = [
        ['receipt_id' => 4190202074, 'transactions' => [['transaction_id' => 5237029540], ['transaction_id' => 5237029538], ['transaction_id' => 5237029536], ['transaction_id' => 5237029534], ['transaction_id' => 5236451659]]],
        ['receipt_id' => 2, 'transactions' => [['transaction_id' => 21]]],
    ];

    public function testAReceiptCostsEveryFeeOfItsLinesShippingAndPaymentWithTheirVat(): void
    {
        $fees = new EtsyFees(self::RECEIPTS, [
            ['entry_id' => 30274266319, 'ledger_type' => 'vat_on_processing_fees', 'reference_type' => 'receipt', 'reference_id' => 4190202074, 'parent_entry_id' => 0, 'amount' => -26],
            ['entry_id' => 30274266095, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => 0, 'parent_entry_id' => 30679862046, 'amount' => -6],
            ['entry_id' => 30274266063, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => 0, 'parent_entry_id' => 30274266007, 'amount' => -3],
            ['entry_id' => 30679862078, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => 0, 'parent_entry_id' => 30274265987, 'amount' => -5],
            ['entry_id' => 30274266053, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => 0, 'parent_entry_id' => 30679862004, 'amount' => -5],
            ['entry_id' => 30679862062, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => 0, 'parent_entry_id' => 30274265979, 'amount' => -4],
            ['entry_id' => 30679862046, 'ledger_type' => 'regulatory_operating_fee', 'reference_type' => 'receipt', 'reference_id' => 4190202074, 'parent_entry_id' => 0, 'amount' => -28],
            ['entry_id' => 30274266007, 'ledger_type' => 'shipping_transaction', 'reference_type' => 'receipt', 'reference_id' => 4190202074, 'parent_entry_id' => 0, 'amount' => -16],
            ['entry_id' => 30679862008, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => 0, 'parent_entry_id' => 30679861978, 'amount' => -8],
            ['entry_id' => 30679862004, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => 5237029534, 'parent_entry_id' => 0, 'amount' => -23],
            ['entry_id' => 30274265987, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => 5236451659, 'parent_entry_id' => 0, 'amount' => -23],
            ['entry_id' => 30274265979, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => 5237029536, 'parent_entry_id' => 0, 'amount' => -20],
            ['entry_id' => 30274265977, 'ledger_type' => 'vat_seller_services', 'reference_type' => 'etsy', 'reference_id' => 0, 'parent_entry_id' => 30274265957, 'amount' => -8],
            ['entry_id' => 30679861978, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => 5237029538, 'parent_entry_id' => 0, 'amount' => -39],
            ['entry_id' => 30274265957, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => 5237029540, 'parent_entry_id' => 0, 'amount' => -39],
            ['entry_id' => 5, 'ledger_type' => 'PAYMENT_GROSS', 'reference_type' => 'payment', 'reference_id' => 208501946034, 'amount' => 2_450],
            ['entry_id' => 6, 'ledger_type' => 'PAYMENT_PROCESSING_FEE', 'reference_type' => 'payment', 'reference_id' => 208501946034, 'amount' => -128],
            ['entry_id' => 7, 'ledger_type' => 'sales_tax', 'reference_type' => 'receipt', 'reference_id' => 4190202074, 'amount' => -300],
            ['entry_id' => 8, 'ledger_type' => 'DISBURSE2', 'reference_type' => 'disbursement', 'reference_id' => 1, 'amount' => -2_000],
            ['entry_id' => 9, 'ledger_type' => 'listing', 'reference_type' => 'listing', 'reference_id' => 5237029540, 'amount' => -20],
        ], [[
            'payment_id' => 208501946034,
            'receipt_id' => 4190202074,
            'amount_fees' => ['amount' => 128, 'divisor' => 100, 'currency_code' => 'EUR'],
            'posted_fees' => ['amount' => 128, 'divisor' => 100, 'currency_code' => 'EUR'],
            'adjusted_fees' => null,
        ]]);

        self::assertSame(381, $fees->of('4190202074')?->amount());
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
            ['entry_id' => 1, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '21', 'amount' => -26],
            ['entry_id' => 2, 'ledger_type' => 'transaction_refund', 'reference_type' => 'transaction', 'reference_id' => '21', 'amount' => 26],
            ['entry_id' => 3, 'ledger_type' => 'REFUND_PROCESSING_FEE', 'reference_type' => 'receipt', 'reference_id' => '2', 'amount' => 50],
            ['entry_id' => 4, 'ledger_type' => 'REFUND_GROSS', 'reference_type' => 'receipt', 'reference_id' => '2', 'amount' => -1_000],
        ], [[
            'payment_id' => 92,
            'receipt_id' => 2,
            'posted_fees' => ['amount' => 100, 'divisor' => 100],
            'adjusted_fees' => ['amount' => 50, 'divisor' => 100],
        ]]);

        self::assertSame(50, $fees->of('2')?->amount());
    }
}
