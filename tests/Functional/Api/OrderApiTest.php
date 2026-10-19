<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderApiTest extends WebTestCase
{
    use SignsInClient;

    public function testPlaceListAndShowOrder(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $product = $this->post($client, '/api/products', ['name' => 'T-shirt', 'sellingPrice' => 2_000, 'variants' => ['S', 'M'], 'typeId' => ProductTypesApi::create($client)])['id'];

        $order = $this->post($client, '/api/orders', ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => $product, 'variant' => 'M', 'quantity' => 2]]]);
        self::assertStringStartsWith('CMD-20260710-', Json::string($order, 'reference'));

        $client->jsonRequest('GET', '/api/orders');
        $listed = $client->getResponse();
        $orders = Json::decode((string) $listed->getContent());
        self::assertSame(4_000, Json::at($orders, 0, 'total'));
        self::assertSame('Japan Expo', Json::at($orders, 0, 'eventName'));
        self::assertSame(0, Json::at($orders, 0, 'unidentifiedLines'));

        $client->jsonRequest('GET', '/api/orders/'.Json::string($order, 'id'));
        self::assertSame('T-shirt — M', Json::at(Json::decode((string) $client->getResponse()->getContent()), 'lines', 0, 'label'));
    }

    public function testAnOrderOnAChannelWithoutEventsKeepsItsComment(): void
    {
        $client = self::signedInClient();
        $product = $this->post($client, '/api/products', ['name' => 'Zine', 'sellingPrice' => 1_000, 'typeId' => ProductTypesApi::create($client)])['id'];
        $gifts = Json::string($this->post($client, '/api/sales-channels', ['name' => 'Cadeaux', 'kind' => 'direct']), 'id');

        $order = Json::string($this->post($client, '/api/orders', ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 1]], 'channelId' => $gifts, 'note' => 'Cadeau pour Léa']), 'id');
        $client->jsonRequest('GET', '/api/orders');
        $listed = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame([null, 'Cadeaux', 'Cadeau pour Léa'], [Json::at($listed, 0, 'eventId'), Json::at($listed, 0, 'channelName'), Json::at($listed, 0, 'note')]);

        $client->jsonRequest('PUT', "/api/orders/$order/note", ['note' => 'Cadeau pour Paul']);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', "/api/orders/$order");
        self::assertSame('Cadeau pour Paul', Json::at(Json::decode((string) $client->getResponse()->getContent()), 'note'));

        $client->jsonRequest('PUT', "/api/orders/$order/note", ['note' => str_repeat('a', 1_001)]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAManualDiscountIsChosenWhenPlacingAnOrderThenWithdrawnAndGrantedAgain(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $product = $this->post($client, '/api/products', ['name' => 'Zine', 'sellingPrice' => 1_000, 'typeId' => ProductTypesApi::create($client)])['id'];
        $gift = Json::string($this->post($client, '/api/discount-rules', ['name' => 'Cadeau', 'manual' => true, 'conditions' => [], 'action' => ['kind' => 'percentOff', 'value' => 10_000]]), 'id');
        $client->jsonRequest('GET', '/api/discount-rules');
        self::assertTrue(Json::at(Json::decode((string) $client->getResponse()->getContent()), 0, 'manual'));
        $body = ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 2]], 'discountRuleIds' => [$gift]];

        $client->jsonRequest('POST', '/api/orders/preview', $body);
        self::assertSame(0, Json::at(Json::decode((string) $client->getResponse()->getContent()), 'total'));
        $order = Json::string($this->post($client, '/api/orders', $body), 'id');
        self::assertSame([2_000, 0], $this->totals($client, $order));

        $client->jsonRequest('DELETE', "/api/orders/$order/discounts/$gift");
        self::assertResponseStatusCodeSame(204);
        self::assertSame([0, 2_000], $this->totals($client, $order));

        $client->jsonRequest('POST', "/api/orders/$order/discounts", ['ruleId' => $gift]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([2_000, 0], $this->totals($client, $order));
        $client->jsonRequest('POST', "/api/orders/$order/discounts", ['ruleId' => $gift]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSuppliesOfferedByTheChannelAreAddedToOrdersAndRemoved(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $type = ProductTypesApi::create($client);
        $product = $this->post($client, '/api/products', ['name' => 'Zine', 'sellingPrice' => 1_000, 'typeId' => $type])['id'];
        $sleeve = Json::string($this->post($client, '/api/products', ['name' => 'Pochette', 'typeId' => $type, 'kind' => 'supply']), 'id');
        $order = Json::string($this->post($client, '/api/orders', ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 1]]]), 'id');
        $client->jsonRequest('GET', '/api/sales-channels');
        $main = Json::string(Json::decode((string) $client->getResponse()->getContent()), 0, 'id');

        $client->jsonRequest('POST', '/api/orders/supplies', ['orderIds' => [$order], 'supplies' => [['supplyId' => $sleeve, 'quantity' => 1]]]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('PUT', "/api/sales-channels/$main/supplies", ['supplyIds' => [$sleeve]]);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', "/api/sales-channels/$main");
        self::assertSame([['id' => $sleeve, 'name' => 'Pochette', 'variants' => []]], Json::at(Json::decode((string) $client->getResponse()->getContent()), 'supplies'));

        $client->jsonRequest('POST', '/api/orders/supplies', ['orderIds' => [$order], 'supplies' => [['supplyId' => $sleeve, 'quantity' => 0]]]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', '/api/orders/supplies', ['orderIds' => [$order], 'supplies' => [['supplyId' => $sleeve, 'quantity' => 2]]]);
        self::assertSame(['updated' => 1], Json::decode((string) $client->getResponse()->getContent()));

        $client->jsonRequest('GET', "/api/orders/$order");
        $view = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame([$main, 2], [Json::at($view, 'channelId'), Json::at($view, 'supplies', 0, 'quantity')]);
        $client->jsonRequest('DELETE', "/api/orders/$order/supplies/".Json::string($view, 'supplies', 0, 'id'));
        self::assertResponseStatusCodeSame(204);
    }

    public function testALineThatAlreadyHasAProductCannotBeLinkedAgain(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $product = $this->post($client, '/api/products', ['name' => 'Zine', 'sellingPrice' => 1_000, 'typeId' => ProductTypesApi::create($client)])['id'];
        $order = Json::string($this->post($client, '/api/orders', ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 1]]]), 'id');
        $client->jsonRequest('GET', "/api/orders/$order");
        $line = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'lines', 0, 'id');

        $client->jsonRequest('PUT', "/api/orders/$order/lines/$line/product", ['productId' => $product]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTwoOrdersOfTheEventAreMerged(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $product = $this->post($client, '/api/products', ['name' => 'Zine', 'sellingPrice' => 1_000, 'typeId' => ProductTypesApi::create($client)])['id'];
        $place = fn (string $at): string => Json::string($this->post($client, '/api/orders', ['placedAt' => $at, 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 1]]]), 'id');
        $first = $place('2026-07-10T15:30');
        $second = $place('2026-07-10T15:31');

        $client->jsonRequest('POST', "/api/orders/$first/merge", ['orderId' => $second]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', "/api/orders/$first");
        $order = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame([2, 2_000], [Json::at($order, 'lines', 0, 'quantity'), Json::at($order, 'total')]);
        $client->jsonRequest('GET', "/api/orders/$second");
        self::assertResponseStatusCodeSame(404);
    }

    public function testMergeCandidatesAreTheOtherUnrefundedOrdersOfTheEventAndSource(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $this->post($client, '/api/events', ['name' => 'Made in Asia', 'location' => 'Bruxelles', 'startDate' => '2026-08-01', 'endDate' => '2026-08-02']);
        $product = $this->post($client, '/api/products', ['name' => 'Zine', 'sellingPrice' => 1_000, 'typeId' => ProductTypesApi::create($client)])['id'];
        $place = fn (string $at): string => Json::string($this->post($client, '/api/orders', ['placedAt' => $at, 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 1]]]), 'id');
        $order = $place('2026-07-10T15:30');
        $sameEvent = $place('2026-07-10T15:31');
        $refunded = $place('2026-07-11T10:00');
        $place('2026-08-01T10:00');
        $client->jsonRequest('POST', "/api/orders/$refunded/refund");

        $client->jsonRequest('GET', "/api/orders/$order/merge-candidates");

        self::assertResponseIsSuccessful();
        self::assertSame([$sameEvent], array_map(static fn (mixed $candidate): string => Json::string($candidate, 'id'), array_values(Json::decode((string) $client->getResponse()->getContent()))));
    }

    public function testAnOrderIsRefundedOnce(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $product = $this->post($client, '/api/products', ['name' => 'Sticker', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client)])['id'];
        $order = Json::string($this->post($client, '/api/orders', ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => $product, 'quantity' => 1]]]), 'id');

        $client->jsonRequest('POST', "/api/orders/$order/refund");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('POST', "/api/orders/$order/refund");
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('GET', '/api/orders');
        self::assertIsString(Json::at(Json::decode((string) $client->getResponse()->getContent()), 0, 'refundedAt'));
    }

    public function testDeleteSelectedOrders(): void
    {
        $client = self::signedInClient();
        $this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $product = $this->post($client, '/api/products', ['name' => 'Sticker', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client)])['id'];
        $place = fn (string $at): string => Json::string($this->post($client, '/api/orders', ['placedAt' => $at, 'lines' => [['productId' => $product, 'quantity' => 1]]]), 'id');
        $first = $place('2026-07-10T15:30');
        $second = $place('2026-07-10T15:31');
        $kept = $place('2026-07-10T15:32');

        $client->jsonRequest('POST', '/api/orders/deletion', ['orderIds' => []]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', '/api/orders/deletion', ['orderIds' => [$first, $second]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['deleted' => 2], Json::decode((string) $client->getResponse()->getContent()));

        $client->jsonRequest('GET', '/api/orders');
        $listed = $client->getResponse();
        $orders = Json::decode((string) $listed->getContent());
        self::assertSame([$kept], array_map(static fn (mixed $order): string => Json::string($order, 'id'), $orders));
    }

    public function testLineViolationsAreReported(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/orders', ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => 'nope', 'quantity' => 0]]]);

        self::assertResponseStatusCodeSame(422);
        $paths = array_column(Json::array(Json::decode((string) $client->getResponse()->getContent()), 'violations'), 'propertyPath');
        self::assertEqualsCanonicalizing(['lines[0].productId', 'lines[0].quantity'], $paths);
    }

    public function testOrdersOfAnEventAreTickedOffOneByOne(): void
    {
        $client = self::signedInClient();
        $product = Json::string($this->post($client, '/api/products', ['name' => 'Sticker', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client)]), 'id');
        $eventId = Json::string($this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']), 'id');
        $order = Json::string($this->post($client, '/api/orders', ['placedAt' => '2026-07-10T15:30', 'lines' => [['productId' => $product, 'quantity' => 2]]]), 'id');

        $client->jsonRequest('PUT', "/api/orders/$order/check");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', "/api/events/$eventId/orders-to-check");
        $orders = Json::decode((string) $client->getResponse()->getContent());
        self::assertTrue(Json::at($orders, 0, 'checked'));
        self::assertSame(2, Json::int($orders, 0, 'lines', 0, 'quantity'));

        $client->jsonRequest('DELETE', "/api/orders/$order/check");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', "/api/events/$eventId/orders-to-check");
        self::assertFalse(Json::at(Json::decode((string) $client->getResponse()->getContent()), 0, 'checked'));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function post(KernelBrowser $client, string $url, array $body): array
    {
        $client->jsonRequest('POST', $url, $body);
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());

        return Json::decode((string) $client->getResponse()->getContent());
    }

    /**
     * @return array{int, int}
     */
    private function totals(KernelBrowser $client, string $order): array
    {
        $client->jsonRequest('GET', "/api/orders/$order");
        $view = Json::decode((string) $client->getResponse()->getContent());

        return [Json::int($view, 'discountTotal'), Json::int($view, 'total')];
    }
}
