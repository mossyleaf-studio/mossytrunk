<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Connector;

use App\Infrastructure\Connector\Etsy\EtsyApiGateway;
use App\Infrastructure\Connector\Etsy\EtsyApp;
use App\Infrastructure\Connector\Etsy\EtsyPayloadMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EtsyApiGatewayTest extends TestCase
{
    public function testPaidReceiptsCarryTheFeesOfTheLedgerSinceTheOldestReceipt(): void
    {
        $requests = [];
        $responses = [
            new JsonMockResponse(['results' => [[
                'receipt_id' => 1,
                'create_timestamp' => 1_893_456_000,
                'total_shipping_cost' => ['amount' => 0, 'divisor' => 100],
                'transactions' => [['transaction_id' => 11, 'listing_id' => 7, 'title' => 'Sticker', 'quantity' => 1, 'price' => ['amount' => 400, 'divisor' => 100]]],
            ]]]),
            new JsonMockResponse(['results' => [
                ['entry_id' => 1, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '11', 'amount' => -26],
                ['entry_id' => 2, 'ledger_type' => 'PAYMENT_PROCESSING_FEE', 'reference_type' => 'payment', 'reference_id' => '91', 'amount' => -46],
            ]]),
            new JsonMockResponse(['results' => []]),
            new JsonMockResponse(['results' => [['payment_id' => 91, 'receipt_id' => 1, 'amount_fees' => ['amount' => 46, 'divisor' => 100], 'adjusted_fees' => null]]]),
        ];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests, &$responses): MockResponse {
            $requests[] = $url;

            return array_shift($responses) ?? throw new \LogicException('Unexpected Etsy request.');
        }, 'https://api.etsy.com');
        $gateway = new EtsyApiGateway($client, new EtsyPayloadMapper(), new MockClock('@'.(1_893_456_000 + 40 * 86_400)));

        $sales = iterator_to_array($gateway->paidReceipts(new EtsyApp('key', 'secret'), 'token', '42'), false);

        self::assertSame(72, $sales[0]->fee?->amount());
        self::assertStringContainsString('/shops/42/receipts?was_paid=true&limit=100&offset=0', $requests[0]);
        self::assertStringContainsString('/shops/42/payment-account/ledger-entries?min_created=1893456000&max_created=1896047999&limit=100', $requests[1]);
        self::assertStringContainsString('min_created=1896048000&max_created=1896912000', $requests[2]);
        self::assertStringContainsString('/shops/42/receipts/1/payments', $requests[3]);
    }

    public function testAReceiptWithoutPaymentHasAnUnknownFee(): void
    {
        $responses = [
            new JsonMockResponse(['results' => [[
                'receipt_id' => 1,
                'create_timestamp' => 1_893_456_000,
                'total_shipping_cost' => ['amount' => 0, 'divisor' => 100],
                'transactions' => [['transaction_id' => 11, 'listing_id' => 7, 'title' => 'Sticker', 'quantity' => 1, 'price' => ['amount' => 400, 'divisor' => 100]]],
            ]]]),
            new JsonMockResponse(['results' => [['entry_id' => 1, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '11', 'amount' => -26]]]),
            new JsonMockResponse(['error' => 'Payment not found'], ['http_code' => 404]),
        ];
        $client = new MockHttpClient(static function () use (&$responses): MockResponse {
            return array_shift($responses) ?? throw new \LogicException('Unexpected Etsy request.');
        }, 'https://api.etsy.com');
        $gateway = new EtsyApiGateway($client, new EtsyPayloadMapper(), new MockClock('@'.(1_893_456_000 + 86_400)));

        $sales = iterator_to_array($gateway->paidReceipts(new EtsyApp('key', 'secret'), 'token', '42'), false);

        self::assertNull($sales[0]->fee);
    }
}
