<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ProductsAnalysisApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsInClient;

    public function testAnUnknownPeriodAnalysesTheLastTwelveMonths(): void
    {
        self::mockTime('2026-11-24 12:00');
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        $event = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
        $client->jsonRequest('POST', '/api/products', ['name' => 'Forêt', 'sellingPrice' => 1_500, 'typeId' => ProductTypesApi::create($client)]);
        $product = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
        $client->jsonRequest('POST', '/api/orders', ['placedAt' => '2026-07-10T12:00', 'lines' => [['productId' => $product, 'variant' => null, 'quantity' => 2]]]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('GET', '/api/reports/products/analysis?period=nonsense');

        self::assertResponseIsSuccessful();
        $analysis = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame(['2025-12-01', '2026-11-24', true], [Json::at($analysis, 'period', 'from'), Json::at($analysis, 'period', 'to'), Json::at($analysis, 'period', 'current')]);
        self::assertSame([$event, 1], [Json::string($analysis, 'events', 0, 'id'), Json::int($analysis, 'events', 0, 'orders')]);
        self::assertSame($event, Json::string($analysis, 'busiestEvent', 'id'));
        $row = Json::array($analysis, 'products', 0);
        self::assertSame(
            [$product, 2, 3_000, '2026-07-10', 1, 200, 0, [2]],
            [$row['id'], $row['units'], $row['revenue'], $row['availableSince'], $row['exposure'], $row['per100Orders'], $row['onHand'], $row['byEvent']],
        );
    }
}
