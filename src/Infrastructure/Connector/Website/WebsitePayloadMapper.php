<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\ExternalLine;
use App\Application\Integration\ExternalSale;
use App\Domain\Order\PaymentMethod;
use App\Domain\Shared\Money;
use App\Infrastructure\Http\Json;

final class WebsitePayloadMapper
{
    /**
     * @return list<ExternalSale>
     */
    public function sales(mixed $orders): array
    {
        return array_map($this->sale(...), self::objects($orders));
    }

    /**
     * @param array<string, mixed> $order
     */
    public function sale(array $order): ExternalSale
    {
        $id = trim(Json::string($order['id'] ?? ''));
        $reference = trim(Json::string($order['reference'] ?? ''));
        $placedAt = self::instant($order['placedAt'] ?? null);
        if ('' === $id || null === $placedAt) {
            throw new \UnexpectedValueException('A website order needs an id and a date.');
        }

        return new ExternalSale(
            $id,
            '' === $reference ? $id : $reference,
            $placedAt,
            array_map(self::line(...), self::objects($order['lines'] ?? null)),
            Money::cents(self::whole($order['goods'] ?? null, 0)),
            Money::cents(self::whole($order['shipping'] ?? null, 0)),
            PaymentMethod::Card,
            Money::zero(),
            self::instant($order['cancelledAt'] ?? null),
        );
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        $text = trim(Json::string($value));
        if ('' === $text) {
            return null;
        }

        return date_create_immutable($text) ?: throw new \UnexpectedValueException('A website order has an unreadable date.');
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function line(array $line): ExternalLine
    {
        $sku = trim(Json::string($line['sku'] ?? ''));
        $name = trim(Json::string($line['name'] ?? ''));
        $variant = trim(Json::string($line['variant'] ?? ''));

        return new ExternalLine(
            $sku,
            '' === $name ? $sku : $name,
            Money::cents(self::whole($line['unitPrice'] ?? null, 0)),
            self::whole($line['quantity'] ?? null, 1),
            '' === $variant ? null : $variant,
            sku: '' === $sku ? null : $sku,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function objects(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value) || !array_all($value, static fn (mixed $item): bool => \is_array($item))) {
            throw new \UnexpectedValueException('The website answered something else than a list of objects.');
        }

        return array_map(Json::object(...), $value);
    }

    private static function whole(mixed $value, int $least): int
    {
        return \is_int($value) && $value >= $least ? $value : throw new \UnexpectedValueException('A website order has an unreadable amount or quantity.');
    }
}
