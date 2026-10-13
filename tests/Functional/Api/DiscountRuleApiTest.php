<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Json;
use App\Tests\Support\ProductTypesApi;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiscountRuleApiTest extends WebTestCase
{
    use SignsInClient;

    public function testCreateAndListARuleWithConditionsAnActionAndAPeriod(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/product-types', ['name' => 'Print']);
        $print = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');
        $client->jsonRequest('POST', '/api/products', ['name' => 'Mousse', 'sellingPrice' => 400, 'variants' => [], 'typeId' => ProductTypesApi::create($client)]);
        $sticker = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');

        $client->jsonRequest('POST', '/api/discount-rules', [
            'name' => '2 prints et 1 sticker pour 15 €',
            'conditions' => [['quantity' => 2, 'targets' => [['kind' => 'type', 'id' => $print]]], ['quantity' => 1, 'targets' => [['kind' => 'product', 'id' => $sticker]]]],
            'action' => ['kind' => 'fixedPrice', 'value' => 1_500],
            'startsOn' => '2026-07-01',
            'endsOn' => null,
        ]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('GET', '/api/discount-rules');
        $rule = Json::array(Json::decode((string) $client->getResponse()->getContent()), 0);
        self::assertSame([['type', 'Print', 2], ['product', 'Mousse', 1]], array_map(static fn (mixed $c): array => [Json::at($c, 'targets', 0, 'kind'), Json::at($c, 'targets', 0, 'name'), Json::at($c, 'quantity')], Json::array($rule, 'conditions')));
        self::assertSame(['kind' => 'fixedPrice', 'value' => 1_500], $rule['action']);
        self::assertSame(['2026-07-01', null], [$rule['startsOn'], $rule['endsOn']]);
    }

    public function testConditionsOnAVariantRoundTrip(): void
    {
        $client = self::signedInClient();
        $print = ProductTypesApi::create($client, 'Print', ['A4', 'A3'], prefixesNames: true);
        $client->jsonRequest('POST', '/api/products', ['name' => 'Forêt', 'sellingPrice' => 1_500, 'typeId' => $print, 'variants' => ['A4', 'A3']]);
        $forest = Json::string(Json::decode((string) $client->getResponse()->getContent()), 'id');

        $client->jsonRequest('POST', '/api/discount-rules', [
            'name' => 'Grands formats',
            'conditions' => [
                ['quantity' => 2, 'targets' => [['kind' => 'type', 'id' => $print, 'variant' => 'a3']]],
                ['quantity' => 1, 'targets' => [['kind' => 'product', 'id' => $forest, 'variant' => 'A4'], ['kind' => 'product', 'id' => $forest, 'variant' => '']]],
            ],
            'action' => ['kind' => 'percentOff', 'value' => 1_000],
        ]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('GET', '/api/discount-rules');
        $conditions = Json::array(Json::decode((string) $client->getResponse()->getContent()), 0, 'conditions');
        self::assertSame([
            ['quantity' => 2, 'targets' => [['kind' => 'type', 'id' => $print, 'name' => 'Print · A3', 'variant' => 'A3', 'collectionId' => null, 'collectionName' => null]]],
            ['quantity' => 1, 'targets' => [
                ['kind' => 'product', 'id' => $forest, 'name' => 'Print Forêt · A4', 'variant' => 'A4', 'collectionId' => null, 'collectionName' => null],
                ['kind' => 'product', 'id' => $forest, 'name' => 'Print Forêt', 'variant' => null, 'collectionId' => null, 'collectionName' => null],
            ]],
        ], $conditions);
    }

    public function testAConditionVariantMustExist(): void
    {
        $client = self::signedInClient();
        $print = ProductTypesApi::create($client, 'Print', ['A4']);

        $client->jsonRequest('POST', '/api/discount-rules', [
            'name' => 'A3',
            'conditions' => [['quantity' => 1, 'targets' => [['kind' => 'type', 'id' => $print, 'variant' => 'A3']]]],
            'action' => ['kind' => 'percentOff', 'value' => 1_000],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('“A3” is not a variant of the type “Print”.', Json::string(Json::decode((string) $client->getResponse()->getContent()), 'detail'));
    }

    public function testInvalidConditionsAndActionAreFieldErrors(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('POST', '/api/discount-rules', [
            'name' => 'Lot',
            'conditions' => [['quantity' => 0, 'targets' => [['kind' => 'variant', 'id' => 'x']]], ['quantity' => 1, 'targets' => []]],
            'action' => ['kind' => 'free', 'value' => 0],
        ]);

        self::assertResponseStatusCodeSame(422);
        $paths = array_column(Json::array(Json::decode((string) $client->getResponse()->getContent()), 'violations'), 'propertyPath');
        self::assertEqualsCanonicalizing(
            ['conditions[0].targets[0].kind', 'conditions[0].targets[0].id', 'conditions[0].quantity', 'conditions[1].targets', 'action.kind', 'action.value'],
            $paths,
        );
    }
}
