<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\CatalogueChanges;
use App\Application\Integration\ExternalLine;
use App\Application\Integration\ExternalSale;
use App\Application\Integration\StockedItem;
use App\Application\Integration\StockedProduct;
use App\Domain\Order\PaymentMethod;
use App\Domain\Shared\Money;
use App\Infrastructure\Http\Json;

final class WebsitePayloadMapper
{
    private const int MAX_REASON = 300;
    private const string NO_REASON = 'HTTP 422';

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

    /**
     * @param list<StockedProduct> $products
     *
     * @return array{products: list<array{id: string, reference: string, name: string, category: string, displayName: string, price: int, variants: list<array{name: ?string, sku: string, stock: int}>}>}
     */
    public function catalogue(array $products): array
    {
        return ['products' => array_map(static fn (StockedProduct $product): array => [
            'id' => $product->id,
            'reference' => $product->reference,
            'name' => $product->name,
            'category' => $product->category,
            'displayName' => $product->displayName,
            'price' => $product->price->amount(),
            'variants' => array_map(static fn (StockedItem $item): array => ['name' => $item->variant, 'sku' => $item->sku, 'stock' => $item->available], $product->items),
        ], $products)];
    }

    /**
     * @param array<mixed> $answer
     */
    public function changes(array $answer): CatalogueChanges
    {
        return new CatalogueChanges(self::whole($answer['created'] ?? null, 0), self::whole($answer['updated'] ?? null, 0), self::whole($answer['retired'] ?? null, 0));
    }

    public function refusalReason(string $answer): string
    {
        $problem = json_decode($answer, true);
        $detail = \is_array($problem) && \is_string($problem['detail'] ?? null) ? $problem['detail'] : '';
        $reason = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $detail));

        return '' === $reason ? self::NO_REASON : mb_substr($reason, 0, self::MAX_REASON);
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
        return \is_int($value) && $value >= $least ? $value : throw new \UnexpectedValueException('The website answered an unreadable amount or quantity.');
    }
}
