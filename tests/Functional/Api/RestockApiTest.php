<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Infrastructure\Security\SecurityUser;
use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RestockApiTest extends WebTestCase
{
    use SignsInClient;

    public function testRestocksNeedASession(): void
    {
        $client = self::createClient();

        $client->jsonRequest('POST', '/api/restocks', []);

        self::assertResponseStatusCodeSame(401);
    }

    public function testARestockIsPlannedRevisedTickedOrderedAndCancelled(): void
    {
        $client = self::signedInClient();
        $supplierId = self::created($client, '/api/suppliers', ['name' => 'Imprimerie du Lac', 'leadDays' => 10]);

        $restockId = self::created($client, '/api/restocks', ['supplierId' => $supplierId, 'note' => ' stickers holo ', 'day' => '2099-06-20', 'leadDays' => 10]);

        $client->jsonRequest('GET', '/api/planning');
        self::assertSame('2099-06-10', Json::at(self::body($client), 'restocks', 0, 'orderBy'));
        self::assertSame('stickers holo', Json::at(self::body($client), 'restocks', 0, 'note'));
        self::assertSame('Imprimerie du Lac', Json::at(self::body($client), 'restocks', 0, 'supplier', 'name'));
        self::assertSame('restock', Json::at(self::body($client), 'later', 0, 'kind'));

        $client->jsonRequest('PUT', "/api/restocks/$restockId", ['supplierId' => $supplierId, 'day' => '2099-06-30', 'leadDays' => 5]);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', '/api/planning');
        self::assertSame('2099-06-25', Json::at(self::body($client), 'restocks', 0, 'orderBy'));

        $client->jsonRequest('PUT', "/api/restocks/$restockId/ordered", ['ordered' => true]);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', '/api/planning');
        self::assertTrue(Json::at(self::body($client), 'restocks', 0, 'ordered'));
        self::assertSame([], Json::array(self::body($client), 'later'));

        $client->jsonRequest('PUT', "/api/restocks/$restockId/ordered", ['ordered' => false]);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', '/api/planning');
        self::assertFalse(Json::at(self::body($client), 'restocks', 0, 'ordered'));

        $productId = self::created($client, '/api/products', ['name' => 'Sticker', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client)]);
        $orderId = self::created($client, '/api/supplier-orders', ['supplierId' => $supplierId, 'orderedOn' => '2026-09-01', 'lines' => [['productId' => $productId, 'quantity' => 10, 'totalPrice' => 200]]]);
        $client->jsonRequest('PUT', "/api/restocks/$restockId/supplier-order", ['supplierOrderId' => $orderId]);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', '/api/planning');
        self::assertSame($orderId, Json::at(self::body($client), 'restocks', 0, 'supplierOrder', 'id'));
        self::assertSame('2026-09-01', Json::at(self::body($client), 'restocks', 0, 'orderedOn'));

        $client->jsonRequest('DELETE', "/api/restocks/$restockId");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', '/api/planning');
        self::assertSame([], Json::array(self::body($client), 'restocks'));
    }

    public function testARestockCanTargetAnEvent(): void
    {
        $client = self::signedInClient();
        $supplierId = self::created($client, '/api/suppliers', ['name' => 'Imprimerie du Lac']);
        $eventId = self::created($client, '/api/events', ['name' => 'Salon', 'location' => 'Lyon', 'startDate' => '2099-06-10', 'endDate' => '2099-06-11']);

        self::created($client, '/api/restocks', ['supplierId' => $supplierId, 'eventId' => $eventId, 'leadDays' => 3]);

        $client->jsonRequest('GET', '/api/planning');
        self::assertSame('2099-06-07', Json::at(self::body($client), 'restocks', 0, 'orderBy'));
        self::assertSame('Salon', Json::at(self::body($client), 'restocks', 0, 'event', 'name'));
    }

    public function testInvalidRestocksAreRejected(): void
    {
        $client = self::signedInClient();
        $supplierId = self::created($client, '/api/suppliers', ['name' => 'Imprimerie du Lac']);
        $eventId = self::created($client, '/api/events', ['name' => 'Salon', 'location' => 'Lyon', 'startDate' => '2099-06-10', 'endDate' => '2099-06-10']);

        $client->jsonRequest('POST', '/api/restocks', ['supplierId' => $supplierId, 'day' => '2099-06-10', 'leadDays' => 0]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', '/api/restocks', ['supplierId' => $supplierId, 'day' => '2099-06-10', 'eventId' => $eventId, 'leadDays' => 3]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', '/api/restocks', ['supplierId' => $supplierId, 'leadDays' => 3]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', '/api/restocks', ['supplierId' => 'nope', 'day' => '2099-06-10', 'leadDays' => 3]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('supplierId', Json::at(self::body($client), 'violations', 0, 'propertyPath'));

        $client->jsonRequest('POST', '/api/restocks', ['supplierId' => '01JZ0000000000000000000000', 'day' => '2099-06-10', 'leadDays' => 3]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherSuppliersOrderCannotBeLinked(): void
    {
        $client = self::signedInClient();
        $lake = self::created($client, '/api/suppliers', ['name' => 'Imprimerie du Lac']);
        $other = self::created($client, '/api/suppliers', ['name' => 'Papeterie']);
        $productId = self::created($client, '/api/products', ['name' => 'Sticker', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client)]);
        $orderId = self::created($client, '/api/supplier-orders', ['supplierId' => $other, 'orderedOn' => '2026-09-01', 'lines' => [['productId' => $productId, 'quantity' => 10, 'totalPrice' => 200]]]);
        $restockId = self::created($client, '/api/restocks', ['supplierId' => $lake, 'day' => '2099-06-20', 'leadDays' => 3]);

        $client->jsonRequest('PUT', "/api/restocks/$restockId/supplier-order", ['supplierOrderId' => $orderId]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Imprimerie du Lac', Json::string(self::body($client), 'detail'));
    }

    public function testTickingNeedsAnOrderedFlag(): void
    {
        $client = self::signedInClient();
        $supplierId = self::created($client, '/api/suppliers', ['name' => 'Imprimerie du Lac']);
        $restockId = self::created($client, '/api/restocks', ['supplierId' => $supplierId, 'day' => '2099-06-20', 'leadDays' => 3]);

        $client->jsonRequest('PUT', "/api/restocks/$restockId/ordered", []);

        self::assertResponseStatusCodeSame(422);
    }

    public function testARestockOfAnotherWorkspaceIsNotFound(): void
    {
        $client = self::signedInClient('Atelier');
        $supplierId = self::created($client, '/api/suppliers', ['name' => 'Imprimerie du Lac']);
        $restockId = self::created($client, '/api/restocks', ['supplierId' => $supplierId, 'day' => '2099-06-20', 'leadDays' => 3]);

        $client->loginUser(SecurityUser::fromUser(self::createMember('Autre atelier')));
        $client->jsonRequest('DELETE', "/api/restocks/$restockId");
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('PUT', "/api/restocks/$restockId/ordered", ['ordered' => true]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testASupplierKeepsItsLeadTime(): void
    {
        $client = self::signedInClient();
        $supplierId = self::created($client, '/api/suppliers', ['name' => 'Imprimerie du Lac', 'leadDays' => 10]);

        $client->jsonRequest('GET', '/api/suppliers');
        self::assertSame(10, Json::at(self::body($client), 0, 'leadDays'));

        $client->jsonRequest('PUT', "/api/suppliers/$supplierId", ['name' => 'Imprimerie du Lac', 'leadDays' => 15]);
        self::assertResponseIsSuccessful();
        self::assertSame(15, Json::at(self::body($client), 'leadDays'));

        $client->jsonRequest('PUT', "/api/suppliers/$supplierId", ['name' => 'Imprimerie du Lac', 'leadDays' => 0]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('PUT', "/api/suppliers/$supplierId", ['name' => 'Imprimerie du Lac']);
        self::assertNull(Json::at(self::body($client), 'leadDays'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function created(KernelBrowser $client, string $uri, array $body): string
    {
        $client->jsonRequest('POST', $uri, $body);
        self::assertResponseStatusCodeSame(201);

        return Json::string(self::body($client), 'id');
    }

    /**
     * @return array<mixed>
     */
    private static function body(KernelBrowser $client): array
    {
        return Json::decode((string) $client->getResponse()->getContent());
    }
}
