<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Application\Integration\StockedItem;
use App\Application\Integration\StockedProduct;
use App\Domain\Order\PaymentMethod;
use App\Domain\Shared\Money;
use App\Infrastructure\Connector\Website\WebsitePayloadMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsitePayloadMapperTest extends TestCase
{
    public function testAnOrderBecomesASaleChargedItsGoodsWithItsShippingAndAZeroFee(): void
    {
        $sale = (new WebsitePayloadMapper())->sale([
            'id' => 'ML-7K2P-9QXA',
            'reference' => 'ML-7K2P-9QXA',
            'placedAt' => '2030-02-03T10:15:00+01:00',
            'lines' => [['sku' => ' WEB-PRINT-A4 ', 'name' => ' Print Forêt ', 'variant' => 'A4', 'unitPrice' => 1_800, 'quantity' => 2]],
            'goods' => 3_400,
            'shipping' => 450,
            'cancelledAt' => null,
        ]);

        self::assertSame(['ML-7K2P-9QXA', 'ML-7K2P-9QXA'], [$sale->id, $sale->reference]);
        self::assertSame('2030-02-03T10:15:00+01:00', $sale->placedAt->format(\DATE_ATOM));
        self::assertSame([3_400, 450], [$sale->charged->amount(), $sale->shipping->amount()]);
        self::assertSame(PaymentMethod::Card, $sale->paymentMethod);
        self::assertSame(0, $sale->fee?->amount(), 'a null fee would be read again on every import');
        self::assertNull($sale->cancelledAt);
        $line = $sale->lines[0];
        self::assertSame(['WEB-PRINT-A4', 'Print Forêt', 'WEB-PRINT-A4', 'A4', 2, 1_800], [$line->externalRef, $line->name, $line->sku, $line->variant, $line->quantity, $line->unitPrice->amount()]);
    }

    public function testACancelledOrderKeepsTheDayItWasCancelled(): void
    {
        $sale = (new WebsitePayloadMapper())->sale(['id' => 'ML-3D8H-4TNC', 'reference' => 'ML-3D8H-4TNC', 'placedAt' => '2030-02-04T18:40:00+01:00', 'lines' => [], 'goods' => 400, 'shipping' => 250, 'cancelledAt' => '2030-02-05T09:00:00+01:00']);

        self::assertSame('2030-02-05T09:00:00+01:00', $sale->cancelledAt?->format(\DATE_ATOM));
    }

    public function testABlankLineNameFallsBackToTheSkuAndABlankVariantIsNoVariant(): void
    {
        $sale = (new WebsitePayloadMapper())->sale(['id' => '1', 'reference' => 'ML-1', 'placedAt' => '2030-02-04T18:40:00+01:00', 'lines' => [['sku' => 'WEB-STICKER', 'name' => '  ', 'variant' => ' ', 'unitPrice' => 400, 'quantity' => 1]], 'goods' => 400, 'shipping' => 0]);

        self::assertSame(['WEB-STICKER', 'WEB-STICKER', null], [$sale->lines[0]->name, $sale->lines[0]->sku, $sale->lines[0]->variant]);
    }

    public function testABlankReferenceFallsBackToTheId(): void
    {
        $sale = (new WebsitePayloadMapper())->sale(['id' => 'ML-1', 'reference' => ' ', 'placedAt' => '2030-02-04T18:40:00+01:00', 'lines' => [], 'goods' => 0, 'shipping' => 0]);

        self::assertSame('ML-1', $sale->reference);
    }

    public function testEveryOrderOfTheListBecomesASale(): void
    {
        $order = ['reference' => 'ML', 'placedAt' => '2030-02-04T18:40:00+01:00', 'lines' => [], 'goods' => 0, 'shipping' => 0];

        $sales = (new WebsitePayloadMapper())->sales([['id' => 'ML-1'] + $order, ['id' => 'ML-2'] + $order]);

        self::assertSame(['ML-1', 'ML-2'], array_column($sales, 'id'));
        self::assertSame([], (new WebsitePayloadMapper())->sales([]));
    }

    #[DataProvider('ordersThatAreNotAList')]
    public function testOrdersThatAreNotAListAreRefused(mixed $orders): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new WebsitePayloadMapper())->sales($orders);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function ordersThatAreNotAList(): iterable
    {
        yield 'nothing' => [null];
        yield 'a text' => ['maintenance'];
        yield 'an object' => [['first' => ['id' => 'ML-1']]];
        yield 'a list of texts' => [['ML-1']];
    }

    /**
     * @param array<string, mixed> $order
     */
    #[DataProvider('unreadableOrders')]
    public function testAnOrderThatCannotBeReadIsRefused(array $order): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new WebsitePayloadMapper())->sale($order + ['id' => 'ML-1', 'reference' => 'ML-1', 'placedAt' => '2030-02-04T18:40:00+01:00', 'lines' => [], 'goods' => 0, 'shipping' => 0]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableOrders(): iterable
    {
        $line = ['sku' => 'WEB-STICKER', 'name' => 'Sticker Mousse', 'variant' => null, 'unitPrice' => 400, 'quantity' => 1];

        yield 'no id' => [['id' => ' ']];
        yield 'no date' => [['placedAt' => null]];
        yield 'a date that is not one' => [['placedAt' => 'not a date']];
        yield 'a cancellation date that is not one' => [['cancelledAt' => 'never']];
        yield 'no amount of goods' => [['goods' => null]];
        yield 'an amount written as text' => [['goods' => '26.00']];
        yield 'an amount in euros' => [['goods' => 26.5]];
        yield 'a negative amount' => [['goods' => -1]];
        yield 'no shipping' => [['shipping' => null]];
        yield 'lines that are not a list' => [['lines' => 'none']];
        yield 'a line that is not an object' => [['lines' => ['WEB-STICKER']]];
        yield 'a line without price' => [['lines' => [['unitPrice' => null] + $line]]];
        yield 'a line priced as text' => [['lines' => [['unitPrice' => '4.00'] + $line]]];
        yield 'a line without quantity' => [['lines' => [['quantity' => null] + $line]]];
        yield 'a line sold zero times' => [['lines' => [['quantity' => 0] + $line]]];
    }

    public function testTheCatalogueBodyCarriesEachProductWithItsIdItsPriceInCentsAndItsStockPerSku(): void
    {
        $body = (new WebsitePayloadMapper())->catalogue([
            new StockedProduct('01JD3V5M8Q0000000000000001', 'PRT-002', 'Forêt', 'Print', 'Print Forêt', Money::cents(1_500), [new StockedItem('A4', 'PRT-002-A4', 12), new StockedItem('A3', 'PRT-002-A3', 0)]),
            new StockedProduct('01JD3V5M8Q0000000000000002', 'STK-001', 'Mousse', 'Sticker', 'Sticker Mousse', Money::cents(400), [new StockedItem(null, 'STK-001', 3)]),
        ]);

        self::assertSame(
            '{"products":['
            .'{"id":"01JD3V5M8Q0000000000000001","reference":"PRT-002","name":"Forêt","category":"Print","displayName":"Print Forêt","price":1500,"variants":[{"name":"A4","sku":"PRT-002-A4","stock":12},{"name":"A3","sku":"PRT-002-A3","stock":0}]},'
            .'{"id":"01JD3V5M8Q0000000000000002","reference":"STK-001","name":"Mousse","category":"Sticker","displayName":"Sticker Mousse","price":400,"variants":[{"name":null,"sku":"STK-001","stock":3}]}'
            .']}',
            json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }

    public function testTheAnswerOfAPublicationGivesWhatChangedOnTheSite(): void
    {
        $changes = (new WebsitePayloadMapper())->changes(['created' => 3, 'updated' => 12, 'retired' => 0]);

        self::assertSame([3, 12, 0], [$changes->created, $changes->updated, $changes->retired]);
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('unreadableChanges')]
    public function testAnAnswerWithoutItsThreeCountsIsRefused(array $answer): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new WebsitePayloadMapper())->changes($answer);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableChanges(): iterable
    {
        yield 'nothing' => [[]];
        yield 'a count missing' => [['created' => 1, 'updated' => 2]];
        yield 'a count written as text' => [['created' => '1', 'updated' => 2, 'retired' => 0]];
        yield 'a negative count' => [['created' => 1, 'updated' => 2, 'retired' => -1]];
    }

    #[DataProvider('refusals')]
    public function testTheReasonOfARefusalIsThePlainTextOfItsDetail(string $answer, string $reason): void
    {
        self::assertSame($reason, (new WebsitePayloadMapper())->refusalReason($answer));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'the detail of the problem' => ['{"title":"Unprocessable","status":422,"detail":"The sku PRT-002-A4 is sent twice."}', 'The sku PRT-002-A4 is sent twice.'];
        yield 'line breaks and control characters become spaces' => [json_encode(['detail' => " The catalogue\r\n\tis\u{0007} empty. "], \JSON_THROW_ON_ERROR), 'The catalogue is empty.'];
        yield 'a long detail is cut' => [json_encode(['detail' => str_repeat('é', 1_000)], \JSON_THROW_ON_ERROR), str_repeat('é', 300)];
        yield 'no detail' => ['{"title":"Unprocessable"}', 'HTTP 422'];
        yield 'a blank detail' => ['{"detail":"  "}', 'HTTP 422'];
        yield 'a detail that is not a text' => ['{"detail":{"sku":"twice"}}', 'HTTP 422'];
        yield 'a page that is not JSON' => ['<html>Unprocessable</html>', 'HTTP 422'];
        yield 'JSON that is not an object' => ['"refused"', 'HTTP 422'];
    }
}
