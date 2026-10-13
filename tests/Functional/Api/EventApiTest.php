<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Json;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventApiTest extends WebTestCase
{
    use SignsInClient;

    public function testEventLifecycle(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-09', 'endDate' => '2026-07-12']);
        self::assertResponseStatusCodeSame(201);
        $id = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');

        $client->jsonRequest('POST', "/api/events/$id/expenses", ['label' => 'Stand', 'amount' => 30_000]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('GET', "/api/events/$id");
        $event = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame('Villepinte', $event['location']);
        self::assertSame(30_000, $event['expensesTotal']);
    }

    public function testDonationsRaiseTheResultWithoutCountingAsTurnover(): void
    {
        $client = self::signedInClient();
        $id = $this->schedule($client, '2026-07-09');
        $client->jsonRequest('POST', "/api/events/$id/expenses", ['label' => 'Stand', 'amount' => 1_000]);

        $client->jsonRequest('POST', "/api/events/$id/donations", ['label' => 'Boîte à dons', 'amount' => 2_500]);
        self::assertResponseStatusCodeSame(201);
        $client->jsonRequest('POST', "/api/events/$id/donations", ['amount' => 500]);
        $unlabelled = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
        $client->jsonRequest('POST', "/api/events/$id/donations", ['amount' => 0]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('GET', "/api/events/$id");
        $event = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame([['Boîte à dons', 2_500], [null, 500]], array_map(static fn (mixed $donation): array => [Json::at($donation, 'label'), Json::int($donation, 'amount')], Json::array($event, 'donations')));
        self::assertSame(3_000, $event['donationsTotal']);

        $client->jsonRequest('GET', "/api/events/$id/report");
        $total = Json::array(Json::decode((string) $client->getResponse()->getContent()), 'total');
        self::assertSame([0, 0, 3_000, 2_000], [$total['turnover'], $total['urssaf'], $total['donations'], $total['result']]);

        $client->jsonRequest('DELETE', "/api/events/$id/donations/$unlabelled");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', "/api/events/$id");
        self::assertSame(2_500, Json::int(Json::decode((string) $client->getResponse()->getContent()), 'donationsTotal'));
    }

    public function testASharedExpenseIsSplitWithTheNextEvent(): void
    {
        $client = self::signedInClient();
        $first = $this->schedule($client, '2031-03-01');
        $client->jsonRequest('POST', "/api/events/$first/expenses", ['label' => 'Nappe', 'amount' => 0, 'sharedOverEvents' => 1]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', "/api/events/$first/expenses", ['label' => 'Nappe', 'amount' => 10_000, 'sharedOverEvents' => 10, 'sharedUntil' => '2031-12-31']);
        self::assertResponseStatusCodeSame(201);
        $second = $this->schedule($client, '2031-04-01');

        $client->jsonRequest('GET', "/api/events/$second");
        $event = Json::decode((string) $client->getResponse()->getContent());
        self::assertSame(5_000, $event['expensesTotal']);
        self::assertSame(['label' => 'Nappe', 'amount' => 5_000, 'fullAmount' => 10_000, 'sharedBy' => 2, 'sharedOverEvents' => 10, 'sharedUntil' => '2031-12-31', 'own' => false, 'originId' => $first], array_diff_key(Json::array($event, 'expenses', 0), ['id' => true, 'originName' => true]));
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/events', ['name' => 'Japan Expo', 'location' => 'Villepinte', 'startDate' => '2026-07-12', 'endDate' => '2026-07-09']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownEventIs404(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('GET', '/api/events/01K00000000000000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    private function schedule(KernelBrowser $client, string $day): string
    {
        $client->jsonRequest('POST', '/api/events', ['name' => 'Marché '.$day, 'location' => 'Lyon', 'startDate' => $day, 'endDate' => $day]);

        return Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
    }
}
