<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\SumUp;

use App\Application\Integration\ExternalLine;
use App\Application\Integration\ExternalSale;
use App\Domain\Order\PaymentMethod;
use App\Domain\Shared\Money;
use App\Infrastructure\Http\Json;

final class SumUpPayloadMapper
{
    private const string KEYPAD_AMOUNT = 'custom amount';
    private const string PAYOUT = 'PAYOUT';
    private const string ONLINE = 'ECOM';

    /**
     * @param array<string, mixed> $transaction
     */
    public function sale(array $transaction): ExternalSale
    {
        $code = Json::string($transaction['transaction_code'] ?? '');
        $paid = self::cents(Json::number($transaction['amount'] ?? 0)) - self::cents(Json::number($transaction['tip_amount'] ?? 0));
        $paymentMethod = self::paymentMethod($transaction['payment_type'] ?? null);

        return new ExternalSale(
            $code,
            $code,
            new \DateTimeImmutable(Json::string($transaction['timestamp'] ?? '')),
            array_map(self::line(...), Json::objects($transaction['products'] ?? [])),
            Money::cents($paid),
            Money::zero(),
            $paymentMethod,
            self::fee($transaction, $paymentMethod),
        );
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public function paidOnline(array $transaction): bool
    {
        $paymentType = $transaction['payment_type'] ?? null;

        return \is_string($paymentType) && self::ONLINE === strtoupper(trim($paymentType));
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private static function fee(array $transaction, ?PaymentMethod $paymentMethod): ?Money
    {
        if (PaymentMethod::Cash === $paymentMethod) {
            return Money::zero();
        }

        $payouts = array_filter(Json::objects($transaction['events'] ?? []), static fn (array $event): bool => self::PAYOUT === ($event['type'] ?? null));
        if ([] === $payouts) {
            return null;
        }

        return Money::cents(array_sum(array_map(static fn (array $payout): int => self::cents(Json::number($payout['fee_amount'] ?? 0)), $payouts)));
    }

    private static function paymentMethod(mixed $paymentType): ?PaymentMethod
    {
        if (!\is_string($paymentType) || '' === trim($paymentType)) {
            return null;
        }

        return 'CASH' === strtoupper(trim($paymentType)) ? PaymentMethod::Cash : PaymentMethod::Card;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function line(array $product): ExternalLine
    {
        $name = self::name($product);

        return new ExternalLine(
            mb_strtolower($name),
            $name,
            Money::cents(self::cents(Json::number($product['price_with_vat'] ?? $product['price'] ?? 0))),
            max(1, (int) Json::number($product['quantity'] ?? 1)),
            self::variant($product),
            self::category($product),
        );
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function name(array $product): string
    {
        $name = trim(Json::string($product['name'] ?? ''));

        return self::KEYPAD_AMOUNT === mb_strtolower($name) ? '' : $name;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function variant(array $product): ?string
    {
        $description = $product['description'] ?? null;

        return \is_string($description) && '' !== trim($description) ? trim($description) : null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function category(array $product): ?string
    {
        $category = $product['category'] ?? $product['category_name'] ?? null;
        if (\is_array($category)) {
            $category = $category['name'] ?? null;
        }

        return \is_string($category) && '' !== trim($category) ? trim($category) : null;
    }

    private static function cents(int|float|string $euros): int
    {
        return (int) round((float) $euros * 100);
    }
}
