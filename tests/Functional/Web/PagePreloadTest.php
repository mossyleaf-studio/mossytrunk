<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PagePreloadTest extends WebTestCase
{
    use SignsInClient;

    public function testAPageCarriesTheApiResponsesItLoadsOnMount(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/products', ['name' => 'Zine </script>', 'sellingPrice' => 1_000, 'typeId' => ProductTypesApi::create($client)]);

        $preloaded = $this->preloadedOn($client, '/products');

        self::assertSame(['/api/products', '/api/product-types', '/api/services', '/api/sales-channels'], array_keys($preloaded));
        self::assertSame('Zine </script>', Json::string($preloaded, '/api/products', 0, 'name'));
    }

    public function testTheOrdersPagePreloadsTheEventItIsFilteredOn(): void
    {
        $client = self::signedInClient();
        $event = Json::string(Json::decode($this->post($client, '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12'])), 'id');

        self::assertArrayHasKey("/api/orders?eventId=$event", $this->preloadedOn($client, "/orders?event=$event"));
        self::assertArrayHasKey('/api/orders', $this->preloadedOn($client, '/orders?event=not-an-event'));
    }

    public function testTheOrdersPagePreloadsTheItemsOfEachConnectedService(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/services', ['service' => 'sumup', 'fields' => ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test']]);

        self::assertArrayHasKey('/api/services/sumup/items', $this->preloadedOn($client, '/orders'));
    }

    public function testAMissingResourceIsLeftForThePageToFetch(): void
    {
        $client = self::signedInClient();

        self::assertSame(['/api/products', '/api/sales-channels', '/api/discount-rules'], array_keys($this->preloadedOn($client, '/orders/01M3SZ3G7ZEJ76R9Z0S55P53TQ')));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(KernelBrowser $client, string $url, array $payload): string
    {
        $client->jsonRequest('POST', $url, $payload);

        return (string) $client->getResponse()->getContent();
    }

    /**
     * @return array<mixed>
     */
    private function preloadedOn(KernelBrowser $client, string $url): array
    {
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return Json::decode($crawler->filter('#app-preload')->text());
    }
}
