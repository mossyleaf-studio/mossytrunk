<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Integration\StockedItem;
use App\Application\Integration\StockedProduct;
use App\Infrastructure\Connector\Website\FakeWebsiteGateway;
use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ServicesApiTest extends WebTestCase
{
    use SignsInClient;

    public function testImportReport(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test']]);
        self::assertResponseStatusCodeSame(201);
        $client->jsonRequest('POST', '/api/events', ['name' => 'Salon', 'location' => 'Lyon', 'startDate' => '2030-03-14', 'endDate' => '2030-03-15']);

        $client->jsonRequest('POST', '/api/services/sumup/import');

        self::assertResponseIsSuccessful();
        $report = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame(['sumup', 'SumUp', 2], [$report['service'], $report['label'], $report['ordersImported']]);
        self::assertSame(['2030-03-21', '2030-03-23'], $report['datesWithoutEvent']);
    }

    public function testTheListNeverExposesASecret(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'mcode', 'api_key' => 'sup_sk_very_secret_9876']]);

        $client->jsonRequest('GET', '/api/services');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('very_secret', $content);
        $sumUp = array_values(array_filter(Json::decode($content), static fn (mixed $service): bool => \is_array($service) && 'sumup' === ($service['key'] ?? null)))[0];
        self::assertSame(['value' => 'MCODE', 'configured' => true, 'hint' => null], Json::at($sumUp, 'connection', 'values', 'merchant_code'));
        self::assertSame(['value' => null, 'configured' => true, 'hint' => '••••9876'], Json::at($sumUp, 'connection', 'values', 'api_key'));
    }

    public function testInvalidFieldsReturnViolations(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'not valid!']]);

        self::assertResponseStatusCodeSame(422);
        $paths = array_values(array_map(static fn (mixed $violation): string => Json::string($violation, 'propertyPath'), Json::array(Json::decode((string) $client->getResponse()->getContent()), 'violations')));
        self::assertSame(['[merchant_code]', '[api_key]'], $paths);
    }

    public function testTheWebsiteIsAddedWithItsAddressAndItsToken(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => ' https://Shop.Example-Studio.fr ', 'token' => 'integration-token-0123456789abcdef']]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('GET', '/api/services');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('integration-token', $content);
        $website = array_values(array_filter(Json::decode($content), static fn (mixed $service): bool => \is_array($service) && 'website' === ($service['key'] ?? null)))[0];
        self::assertSame(['value' => 'https://Shop.Example-Studio.fr', 'configured' => true, 'hint' => null], Json::at($website, 'connection', 'values', 'address'));
        self::assertSame(['value' => null, 'configured' => true, 'hint' => '••••cdef'], Json::at($website, 'connection', 'values', 'token'));
        self::assertSame(['online', 'link_by_hand', true], [Json::at($website, 'connection', 'salesContext'), Json::at($website, 'connection', 'unknownItems'), Json::at($website, 'connection', 'configured')]);
    }

    #[DataProvider('addressesThatAreNotAPublicHttpsOrigin')]
    public function testTheSiteAddressMustBeAPublicHttpsOrigin(string $address): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => $address, 'token' => 'integration-token-0123456789abcdef']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['[address]', 'Type only the https address of the site, such as https://example.com.']], array_map(
            static fn (mixed $violation): array => [Json::at($violation, 'propertyPath'), Json::at($violation, 'title')],
            Json::array(Json::decode((string) $client->getResponse()->getContent()), 'violations'),
        ));
        $client->jsonRequest('GET', '/api/services');
        self::assertStringNotContainsString('"configured":true', (string) $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatAreNotAPublicHttpsOrigin(): iterable
    {
        yield 'plain http' => ['http://shop.example'];
        yield 'another scheme' => ['ftp://shop.example'];
        yield 'no scheme' => ['shop.example'];
        yield 'a port' => ['https://shop.example:8443'];
        yield 'a path' => ['https://shop.example/integrations'];
        yield 'a trailing slash' => ['https://shop.example/'];
        yield 'a query' => ['https://shop.example?next=1'];
        yield 'a fragment' => ['https://shop.example#top'];
        yield 'a user' => ['https://admin@shop.example'];
        yield 'a user and a password' => ['https://admin:secret@shop.example'];
        yield 'a host hidden behind a user' => ['https://shop.example@127.0.0.1'];
        yield 'an IPv4 address' => ['https://127.0.0.1'];
        yield 'an IPv4 address written as one number' => ['https://2130706433'];
        yield 'an IPv4 address written in hexadecimal' => ['https://0x7f.0.0.1'];
        yield 'an IPv6 address' => ['https://[::1]'];
        yield 'a name without domain' => ['https://localhost'];
        yield 'a name ending with a dot' => ['https://localhost.'];
        yield 'a backslash' => ['https://shop.example\\@evil.example'];
        yield 'a space' => ['https://shop.example evil.example'];
        yield 'a second line' => ["https://shop.example\nhttps://evil.example"];
    }

    public function testANewSiteAddressIsRefusedWithoutItsToken(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => 'https://shop.example', 'token' => 'integration-token-0123456789abcdef']]);

        $client->jsonRequest('PUT', '/api/services/website', ['fields' => ['address' => 'https://collector.example', 'token' => '']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('“Token” must be typed again: the value kept belongs to the previous access.', Json::at(Json::decode((string) $client->getResponse()->getContent()), 'detail'));
        $client->jsonRequest('GET', '/api/services');
        $website = array_values(array_filter(Json::decode((string) $client->getResponse()->getContent()), static fn (mixed $service): bool => \is_array($service) && 'website' === ($service['key'] ?? null)))[0];
        self::assertSame(['value' => 'https://shop.example', 'configured' => true, 'hint' => null], Json::at($website, 'connection', 'values', 'address'));
        self::assertSame(['value' => null, 'configured' => true, 'hint' => '••••cdef'], Json::at($website, 'connection', 'values', 'token'));

        $client->jsonRequest('PUT', '/api/services/website', ['fields' => ['address' => 'https://collector.example', 'token' => 'another-token-0123456789abcd9876']]);
        self::assertResponseStatusCodeSame(204);
    }

    #[DataProvider('tokensThatCannotBeSentAsBearer')]
    public function testATokenHoldsNoSpaceNorAccent(string $token): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => 'https://shop.example', 'token' => $token]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['[token]', 'A token holds no space and no accented character.']], array_map(
            static fn (mixed $violation): array => [Json::at($violation, 'propertyPath'), Json::at($violation, 'title')],
            Json::array(Json::decode((string) $client->getResponse()->getContent()), 'violations'),
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tokensThatCannotBeSentAsBearer(): iterable
    {
        yield 'a space in the middle' => ['integration-token 0123456789abcdef'];
        yield 'an accent' => ['jeton-d-intégration-0123456789abcdef'];
        yield 'a second line' => ["integration-token\n0123456789abcdef"];
    }

    public function testOptionsCanBeChangedWithoutRetypingTheSecret(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test']]);

        $client->jsonRequest('PUT', '/api/services/sumup', ['fields' => ['merchant_code' => 'MCODE', 'api_key' => ''], 'salesContext' => 'online', 'unknownItems' => 'link_by_hand']);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('DELETE', '/api/services/sumup');
        self::assertResponseStatusCodeSame(204);
    }

    public function testAnUnknownServiceIsNotFound(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/services', ['service' => 'paypal', 'fields' => []]);
        self::assertResponseStatusCodeSame(404);

        $client->jsonRequest('POST', '/api/services/sumup/import');
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheSumUpCatalogueDownloadsAsCsv(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/products', ['name' => 'Zine', 'sellingPrice' => 1_000, 'typeId' => ProductTypesApi::create($client, 'Livre')]);

        $client->request('GET', '/api/services/sumup/catalogue.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('X-Item-Count', '1');
        self::assertStringStartsWith('attachment; filename=catalogue-sumup-', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('Zine,,10.00,,No,,', (string) $client->getResponse()->getContent());

        $client->request('GET', '/api/services/etsy/catalogue.csv');
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('GET', '/api/services');
        $services = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame(['etsy' => false, 'sumup' => true, 'website' => false], array_combine(array_map(static fn (mixed $service): string => Json::string($service, 'key'), array_values($services)), array_map(static fn (mixed $service): bool => true === Json::at($service, 'exportsCatalogue'), array_values($services))));
    }

    public function testASumUpCatalogueIsUploaded(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test'], 'unknownItems' => 'link_by_hand']);
        $path = tempnam(sys_get_temp_dir(), 'catalogue');
        file_put_contents($path, "Item name,Variations,Price\r\nBougie,,8.00\r\n");

        $client->request('POST', '/api/services/sumup/catalogue', files: ['file' => new UploadedFile($path, 'catalogue.csv', 'text/csv', test: true)]);

        self::assertResponseIsSuccessful();
        $report = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame([1, 0, 1], [$report['itemsRead'], $report['itemsLinked'], $report['itemsToLink']]);

        $client->request('POST', '/api/services/sumup/catalogue');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnItemIsLinkedToSeveralProducts(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test'], 'unknownItems' => 'link_by_hand']);
        $type = ProductTypesApi::create($client, 'Lot');
        $client->jsonRequest('POST', '/api/products', ['name' => 'Bougie', 'sellingPrice' => 800, 'typeId' => $type]);
        $candle = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
        $path = tempnam(sys_get_temp_dir(), 'catalogue');
        file_put_contents($path, "Item name,Variations,Price\r\nCoffret,,20.00\r\n");
        $client->request('POST', '/api/services/sumup/catalogue', files: ['file' => new UploadedFile($path, 'catalogue.csv', 'text/csv', test: true)]);
        $client->jsonRequest('GET', '/api/services/sumup/items');
        $item = Json::string(Json::decode((string) $client->getResponse()->getContent()), 0, 'id');

        $client->jsonRequest('PUT', "/api/services/sumup/items/$item", ['lines' => []]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('PUT', "/api/services/sumup/items/$item", ['lines' => [['productId' => $candle, 'variant' => null, 'quantity' => 2]]]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', '/api/services/sumup/items');
        self::assertSame([['productId' => $candle, 'name' => 'Bougie', 'variant' => null, 'quantity' => 2]], Json::array(Json::decode((string) $client->getResponse()->getContent()), 0, 'components'));
    }

    public function testPublishingImportsTheSitesSalesFirstSoTheStockSentCountsThem(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => 'https://shop.example', 'token' => 'integration-token-0123456789abcdef']]);
        $sticker = self::product($client, ['name' => 'Mousse', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client, 'Sticker'), 'reference' => 'WEB-STICKER']);
        self::product($client, ['name' => 'Forêt', 'sellingPrice' => 1_800, 'variants' => ['A4', 'A3'], 'typeId' => ProductTypesApi::create($client, 'Print', ['A4', 'A3']), 'reference' => 'WEB-PRINT']);
        $client->jsonRequest('POST', '/api/stock/restock', ['productId' => $sticker, 'variant' => null, 'quantity' => 10, 'totalPaid' => 1_000]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('POST', '/api/services/website/catalogue/publish');

        self::assertResponseIsSuccessful();
        $report = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame(['website', 2, 0], [Json::at($report, 'sales', 'service'), Json::at($report, 'sales', 'ordersImported'), Json::at($report, 'sales', 'ordersWaitingForItems')]);
        self::assertSame(['label' => 'Website', 'products' => 2, 'created' => 2, 'updated' => 0, 'retired' => 0, 'descriptions' => []], Json::at($report, 'catalogue'));
        self::assertSame(
            ['WEB-PRINT-A4' => 0, 'WEB-PRINT-A3' => 0, 'WEB-STICKER' => 8],
            self::stockSent(),
            'the two stickers the site sold are gone before the stock is sent',
        );
    }

    public function testOrdersWaitingForAnItemAreReportedAndDoNotBlockThePublication(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => 'https://shop.example', 'token' => 'integration-token-0123456789abcdef']]);
        self::product($client, ['name' => 'Mousse', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client, 'Sticker'), 'reference' => 'WEB-STICKER']);

        $client->jsonRequest('POST', '/api/services/website/catalogue/publish');

        self::assertResponseIsSuccessful();
        $report = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame([1, 1], [Json::at($report, 'sales', 'ordersImported'), Json::at($report, 'sales', 'ordersWaitingForItems')]);
        self::assertSame(1, Json::at($report, 'catalogue', 'products'));
        self::assertSame(['WEB-STICKER' => 0], self::stockSent());
    }

    public function testAServiceThatPublishesNoCatalogueImportsNothingEither(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test'], 'salesContext' => 'online']);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/services/sumup/catalogue/publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('SumUp does not take the catalogue.', Json::at(Json::decode((string) $client->getResponse()->getContent()), 'detail'));
        $client->jsonRequest('GET', '/api/orders');
        self::assertSame([], Json::decode((string) $client->getResponse()->getContent()));
    }

    public function testNothingIsPublishedWhenTheImportFails(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => 'https://'.FakeWebsiteGateway::UNREACHABLE_HOST, 'token' => 'integration-token-0123456789abcdef']]);
        self::assertResponseStatusCodeSame(201);
        self::product($client, ['name' => 'Mousse', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client, 'Sticker'), 'reference' => 'WEB-STICKER']);

        $client->jsonRequest('POST', '/api/services/website/catalogue/publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Cannot reach Website: down.example', Json::at(Json::decode((string) $client->getResponse()->getContent()), 'detail'));
        self::assertSame([], self::stockSent());
    }

    public function testACatalogueTheSiteRefusesLeavesItsSalesImported(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => 'https://'.FakeWebsiteGateway::REFUSING_HOST, 'token' => 'integration-token-0123456789abcdef']]);
        self::assertResponseStatusCodeSame(201);
        self::product($client, ['name' => 'Mousse', 'sellingPrice' => 400, 'typeId' => ProductTypesApi::create($client, 'Sticker'), 'reference' => 'WEB-STICKER']);

        $client->jsonRequest('POST', '/api/services/website/catalogue/publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Website refused the catalogue: HTTP 422', Json::at(Json::decode((string) $client->getResponse()->getContent()), 'detail'));
        self::assertSame([], self::stockSent());
        $client->jsonRequest('GET', '/api/orders');
        self::assertCount(1, Json::decode((string) $client->getResponse()->getContent()));
    }

    public function testProductsTheImportCreatesAreSentInTheSamePublication(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'website', 'fields' => ['address' => 'https://shop.example', 'token' => 'integration-token-0123456789abcdef'], 'unknownItems' => 'create_product']);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/services/website/catalogue/publish');

        self::assertResponseIsSuccessful();
        $report = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame([2, 2, 2], [Json::at($report, 'sales', 'productsCreated'), Json::at($report, 'catalogue', 'products'), Json::at($report, 'catalogue', 'created')]);
        self::assertCount(2, self::stockSent());
    }

    public function testOnlyTheWebsitePublishesACatalogue(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('GET', '/api/services');

        $services = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame(['etsy' => false, 'sumup' => false, 'website' => true], array_combine(array_map(static fn (mixed $service): string => Json::string($service, 'key'), array_values($services)), array_map(static fn (mixed $service): bool => true === Json::at($service, 'publishesCatalogue'), array_values($services))));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function product(KernelBrowser $client, array $payload): string
    {
        $client->jsonRequest('POST', '/api/products', $payload);
        self::assertResponseStatusCodeSame(201);

        return Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
    }

    /**
     * @return array<string, int>
     */
    private static function stockSent(): array
    {
        $items = array_merge(...array_map(static fn (StockedProduct $product): array => $product->items, self::getContainer()->get(FakeWebsiteGateway::class)->published()));

        return array_combine(array_map(static fn (StockedItem $item): string => $item->sku, $items), array_map(static fn (StockedItem $item): int => $item->available, $items));
    }
}
