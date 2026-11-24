<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class QueryCountTest extends WebTestCase
{
    use SignsInClient;

    public function testListsAndReportsDoNotQueryOncePerOrderOrProduct(): void
    {
        $client = self::signedInClient();
        $type = ProductTypesApi::create($client);
        $client->jsonRequest('POST', '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $event = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
        $urls = ['/api/dashboard', '/api/events', "/api/events/$event/report", '/api/products', '/api/orders', '/api/reports/products?period=2026', '/api/reports/products/analysis?period=2026'];

        $this->sellNewProducts($client, $type, 1);
        $before = array_map(fn (string $url): int => $this->queriesOf($client, $url), $urls);

        $this->sellNewProducts($client, $type, 4);
        $after = array_map(fn (string $url): int => $this->queriesOf($client, $url), $urls);

        self::assertSame(array_combine($urls, $before), array_combine($urls, $after));
    }

    private function sellNewProducts(KernelBrowser $client, string $type, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $client->jsonRequest('POST', '/api/products', ['name' => uniqid('Print '), 'sellingPrice' => 1_500, 'typeId' => $type]);
            $product = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
            $client->jsonRequest('PUT', "/api/products/$product/channel-prices/".$this->mainChannel($client), ['price' => 1_600]);
            $client->jsonRequest('POST', '/api/orders', ['placedAt' => \sprintf('2026-07-10T1%d:00', $i), 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 1]]]);
            self::assertResponseStatusCodeSame(201);
        }
    }

    private function mainChannel(KernelBrowser $client): string
    {
        $client->jsonRequest('GET', '/api/sales-channels');

        return Json::string(Json::decode((string) $client->getResponse()->getContent()), 0, 'id');
    }

    private function queriesOf(KernelBrowser $client, string $url): int
    {
        $client->enableProfiler();
        $client->jsonRequest('GET', $url);
        self::assertResponseIsSuccessful();
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
