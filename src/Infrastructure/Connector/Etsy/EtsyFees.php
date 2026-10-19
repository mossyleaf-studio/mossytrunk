<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

use App\Domain\Shared\Money;
use App\Infrastructure\Http\Json;

final class EtsyFees
{
    private const string NOT_A_FEE = '/^(payment_gross|refund_gross|disburse|sales_tax)/';

    /** @var array<string, int> */
    private array $cents = [];

    /** @var array<string, true> */
    private array $paid = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $entries = [];

    /**
     * @param list<array<string, mixed>> $receipts
     * @param list<array<string, mixed>> $entries
     * @param list<array<string, mixed>> $payments
     */
    public function __construct(array $receipts, array $entries, array $payments)
    {
        $owners = self::owners($receipts, $payments);
        $entryReceipts = [];
        foreach ($entries as $entry) {
            $receiptId = $owners[strtolower(Json::string($entry['reference_type'] ?? '')).':'.Json::string($entry['reference_id'] ?? '')] ?? null;
            if (null !== $receiptId) {
                $entryReceipts[Json::string($entry['entry_id'] ?? '')] = $receiptId;
            }
        }

        foreach ($entries as $entry) {
            $receiptId = $entryReceipts[Json::string($entry['entry_id'] ?? '')] ?? $entryReceipts[Json::string($entry['parent_entry_id'] ?? '')] ?? null;
            if (null === $receiptId) {
                continue;
            }
            $this->entries[$receiptId][] = $entry;
            if ('payment' === strtolower(Json::string($entry['reference_type'] ?? ''))) {
                $this->paid[$receiptId] = true;
            }
            if (1 !== preg_match(self::NOT_A_FEE, strtolower(Json::string($entry['ledger_type'] ?? '')))) {
                $this->cents[$receiptId] = ($this->cents[$receiptId] ?? 0) - (int) Json::number($entry['amount'] ?? 0);
            }
        }
    }

    public function of(string $receiptId): ?Money
    {
        if (!isset($this->paid[$receiptId])) {
            return null;
        }

        return Money::cents(max(0, $this->cents[$receiptId] ?? 0));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entriesOf(string $receiptId): array
    {
        return $this->entries[$receiptId] ?? [];
    }

    /**
     * @param list<array<string, mixed>> $receipts
     * @param list<array<string, mixed>> $payments
     *
     * @return array<string, string>
     */
    private static function owners(array $receipts, array $payments): array
    {
        $owners = [];
        foreach ($receipts as $receipt) {
            $receiptId = Json::string($receipt['receipt_id'] ?? '');
            $owners['receipt:'.$receiptId] = $receiptId;
            foreach (Json::objects($receipt['transactions'] ?? []) as $transaction) {
                $owners['transaction:'.Json::string($transaction['transaction_id'] ?? '')] = $receiptId;
            }
        }
        foreach ($payments as $payment) {
            $owners['payment:'.Json::string($payment['payment_id'] ?? '')] = Json::string($payment['receipt_id'] ?? '');
        }

        return $owners;
    }
}
