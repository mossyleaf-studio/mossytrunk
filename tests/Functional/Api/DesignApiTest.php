<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DesignApiTest extends WebTestCase
{
    use SignsInClient;

    public function testFromGabaritToProduct(): void
    {
        $client = self::signedInClient();
        $postcard = ProductTypesApi::create($client, 'Carte postale');
        $client->jsonRequest('POST', '/api/gabarits', ['name' => 'Carte postale', 'typeId' => $postcard, 'sellingPrice' => 250, 'adaptations' => ['Marges 5 mm']]);
        self::assertResponseStatusCodeSame(201);
        $gabaritId = Json::string(self::body($client), 'id');

        $client->jsonRequest('POST', '/api/designs', ['name' => 'Clairière']);
        $designId = Json::string(self::body($client), 'id');
        $client->jsonRequest('POST', "/api/designs/$designId/declinations", ['gabaritId' => $gabaritId]);
        $declinationId = Json::string(self::body($client), 'id');

        $client->jsonRequest('POST', "/api/designs/$designId/validation");
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('PUT', "/api/designs/$designId/declinations/$declinationId/adaptations", ['adaptation' => 'Marges 5 mm', 'done' => true]);
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('POST', "/api/designs/$designId/validation");
        self::assertSame(1, self::body($client)['productsCreated']);

        $client->jsonRequest('GET', '/api/products');
        self::assertSame('Clairière', Json::at(self::body($client), 0, 'name'));

        $client->jsonRequest('GET', '/api/designs');
        self::assertSame('validated', Json::at(self::body($client), 'standalone', 0, 'status'));
    }

    public function testAGabaritIsDeletedOnceNoDesignIsDeclinedOnIt(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/gabarits', ['name' => 'Carte postale', 'typeId' => ProductTypesApi::create($client, 'Carte postale'), 'sellingPrice' => 250]);
        $gabaritId = Json::string(self::body($client), 'id');
        $client->jsonRequest('POST', '/api/designs', ['name' => 'Clairière']);
        $designId = Json::string(self::body($client), 'id');
        $client->jsonRequest('POST', "/api/designs/$designId/declinations", ['gabaritId' => $gabaritId]);
        $declinationId = Json::string(self::body($client), 'id');
        $client->jsonRequest('POST', "/api/designs/$designId/validation");

        $client->jsonRequest('DELETE', "/api/gabarits/$gabaritId");
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('DELETE', "/api/designs/$designId/declinations/$declinationId");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('DELETE', "/api/gabarits/$gabaritId");
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', '/api/gabarits');
        self::assertSame([], self::body($client));
        $client->jsonRequest('GET', '/api/products');
        self::assertSame('Clairière', Json::at(self::body($client), 0, 'name'));
    }

    public function testGabaritNeedsANameAndAType(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/gabarits', ['name' => '', 'sellingPrice' => -1]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name', 'typeId', 'sellingPrice'], array_column(Json::array(self::body($client), 'violations'), 'propertyPath'));
    }

    public function testGabaritVariantsJoinItsType(): void
    {
        $client = self::signedInClient();
        $print = ProductTypesApi::create($client, 'Print', ['A4']);

        $client->jsonRequest('POST', '/api/gabarits', ['name' => 'Tirage', 'typeId' => $print, 'sellingPrice' => 1_500, 'variants' => ['a4', 'A3']]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('GET', '/api/gabarits');
        self::assertSame(['A4', 'A3'], Json::at(self::body($client), 0, 'variants'));
        $client->jsonRequest('GET', '/api/product-types');
        self::assertSame(['A4', 'A3'], Json::at(self::body($client), 0, 'variants'));
    }

    public function testDeletingACollectionKeepsItsDesignsWithoutCollection(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/design-collections', ['name' => 'Automne']);
        $collectionId = Json::string(self::body($client), 'id');
        $client->jsonRequest('POST', '/api/designs', ['name' => 'Clairière', 'collectionId' => $collectionId]);

        $client->jsonRequest('DELETE', "/api/design-collections/$collectionId");
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', '/api/designs');
        self::assertSame([], Json::array(self::body($client), 'collections'));
        self::assertSame('Clairière', Json::string(self::body($client), 'standalone', 0, 'name'));
    }

    public function testAnExistingProductGetsANewDesignOrJoinsOne(): void
    {
        $client = self::signedInClient();
        $sticker = self::gabarit($client, 'Sticker');
        $print = self::gabarit($client, 'Print');
        $client->jsonRequest('POST', '/api/design-collections', ['name' => 'Sous-bois']);
        $collectionId = Json::string(self::body($client), 'id');
        [$heron, $loutre, $aigrette] = array_map(static fn (string $name): string => self::product($client, $name), ['Héron', 'Loutre', 'Aigrette']);

        $client->jsonRequest('POST', "/api/products/$heron/design", ['gabaritId' => $sticker, 'collectionId' => $collectionId]);
        self::assertResponseStatusCodeSame(201);
        $inCollection = Json::string(self::body($client), 'designId');
        $client->jsonRequest('POST', "/api/products/$loutre/design", ['gabaritId' => $sticker]);
        $standalone = Json::string(self::body($client), 'designId');
        $client->jsonRequest('POST', "/api/products/$aigrette/design", ['gabaritId' => $print, 'designId' => $inCollection]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($inCollection, Json::string(self::body($client), 'designId'));

        $client->jsonRequest('GET', '/api/designs');
        self::assertSame([$inCollection, 'Héron'], [Json::at(self::body($client), 'collections', 0, 'designs', 0, 'id'), Json::at(self::body($client), 'collections', 0, 'designs', 0, 'name')]);
        self::assertSame($standalone, Json::at(self::body($client), 'standalone', 0, 'id'));
        $client->jsonRequest('GET', "/api/products/$aigrette");
        self::assertSame(['id' => $inCollection, 'name' => 'Héron'], Json::at(self::body($client), 'design'));
    }

    private static function gabarit(KernelBrowser $client, string $name): string
    {
        $client->jsonRequest('POST', '/api/gabarits', ['name' => $name, 'typeId' => ProductTypesApi::create($client, $name), 'sellingPrice' => 400]);

        return Json::string(self::body($client), 'id');
    }

    private static function product(KernelBrowser $client, string $name): string
    {
        $client->jsonRequest('POST', '/api/products', ['name' => $name, 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client, "Type $name")]);

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
