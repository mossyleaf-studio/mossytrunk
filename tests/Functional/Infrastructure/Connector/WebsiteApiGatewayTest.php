<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Connector;

use App\Application\Integration\Exception\AccessRefused;
use App\Application\Integration\Exception\IntegrationDisabled;
use App\Application\Integration\Exception\ServiceUnreachable;
use App\Domain\Shared\Exception\DomainException;
use App\Infrastructure\Connector\Website\WebsiteAccess;
use App\Infrastructure\Connector\Website\WebsiteApiGateway;
use App\Infrastructure\Connector\Website\WebsitePayloadMapper;
use App\Tests\Support\DomainExceptions;
use App\Tests\Support\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebsiteApiGatewayTest extends TestCase
{
    private const array ORDER = ['id' => 'ML-7K2P-9QXA', 'reference' => 'ML-7K2P-9QXA', 'placedAt' => '2030-02-03T10:15:00+01:00', 'lines' => [['sku' => 'WEB-STICKER', 'name' => 'Sticker Mousse', 'variant' => null, 'unitPrice' => 400, 'quantity' => 2]], 'goods' => 800, 'shipping' => 450, 'cancelledAt' => null];

    public function testTheOrdersAreReadWithTheTokenAsBearerWithinTimeLimits(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, Json::at($options, 'normalized_headers', 'authorization', 0), Json::at($options, 'normalized_headers', 'accept-encoding', 0), Json::at($options, 'max_redirects'), Json::at($options, 'timeout'), Json::at($options, 'max_duration')];

            return new JsonMockResponse(['orders' => [self::ORDER]]);
        });

        $sales = self::orders($client, 'https://shop.example');

        self::assertSame([['GET', 'https://shop.example/integrations/orders', 'Authorization: Bearer integration-token', 'Accept-Encoding: identity', 0, 20.0, 60.0]], $requests);
        self::assertSame(['ML-7K2P-9QXA'], array_column($sales, 'id'));
        self::assertSame([800, 450, 0], [$sales[0]->charged->amount(), $sales[0]->shipping->amount(), $sales[0]->fee?->amount()]);
    }

    public function testARedirectIsNotFollowed(): void
    {
        $requested = [];
        $spy = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://93.184.216.35/elsewhere', 'response_headers' => ['Location' => 'https://93.184.216.35/elsewhere']]);
        });

        DomainExceptions::assertThrown(new ServiceUnreachable('Website', 'HTTP 302'), static fn (): array => self::orders(new NoPrivateNetworkHttpClient($spy), 'https://93.184.216.34'));

        self::assertSame(['https://93.184.216.34/integrations/orders'], $requested);
    }

    #[DataProvider('refusals')]
    public function testEachRefusalOfTheSiteIsToldApart(MockResponse $response, DomainException $expected): void
    {
        DomainExceptions::assertThrown($expected, static fn (): array => self::orders(new MockHttpClient($response), 'https://shop.example'));
    }

    /**
     * @return iterable<string, array{MockResponse, DomainException}>
     */
    public static function refusals(): iterable
    {
        $unreachable = new ServiceUnreachable('Website', 'shop.example');

        yield 'a wrong token' => [new MockResponse('{"detail":"token integration-token refused"}', ['http_code' => 401]), new AccessRefused('Website')];
        yield 'a forbidden token' => [new MockResponse('', ['http_code' => 403]), new AccessRefused('Website')];
        yield 'the integration switched off' => [new MockResponse('', ['http_code' => 404]), new IntegrationDisabled('Website')];
        yield 'a failing site' => [new MockResponse('SQLSTATE[08006] at 10.0.0.12', ['http_code' => 500]), new ServiceUnreachable('Website', 'HTTP 500')];
        yield 'no answer' => [new MockResponse('', ['error' => 'Could not resolve host db.internal for token integration-token']), $unreachable];
        yield 'a page that is not JSON' => [new MockResponse('<html>Welcome</html>'), $unreachable];
        yield 'JSON without orders' => [new JsonMockResponse(['status' => 'ok']), $unreachable];
        yield 'orders that are not a list' => [new JsonMockResponse(['orders' => 'maintenance']), $unreachable];
        yield 'an order without its amount' => [new JsonMockResponse(['orders' => [['total' => 800] + array_diff_key(self::ORDER, ['goods' => 0])]]), $unreachable];
        yield 'an answer longer than five megabytes' => [new MockResponse(['{"orders":[]', str_repeat(' ', 5 * 1024 * 1024), '}']), $unreachable];
        yield 'an order without date' => [new JsonMockResponse(['orders' => [['placedAt' => ''] + self::ORDER]]), $unreachable];
    }

    public function testAnAnswerOfFiveMegabytesIsRead(): void
    {
        $body = '{"orders":[]'.str_repeat(' ', 5 * 1024 * 1024 - 13).'}';

        self::assertSame([], self::orders(new MockHttpClient(new MockResponse($body)), 'https://shop.example'));
    }

    #[DataProvider('privateAddresses')]
    public function testAPrivateOrLoopbackAddressIsNeverRequested(string $address, string $host): void
    {
        $requested = [];
        $spy = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new JsonMockResponse(['orders' => []]);
        });

        DomainExceptions::assertThrown(new ServiceUnreachable('Website', $host), static fn (): array => self::orders(new NoPrivateNetworkHttpClient($spy), $address));

        self::assertSame([], $requested);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function privateAddresses(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1', '127.0.0.1'];
        yield 'loopback by name' => ['https://localhost', 'localhost'];
        yield 'loopback in IPv6' => ['https://[::1]', '[::1]'];
        yield 'private class A' => ['https://10.0.0.5', '10.0.0.5'];
        yield 'private class B' => ['https://172.16.4.2', '172.16.4.2'];
        yield 'private class C' => ['https://192.168.1.10', '192.168.1.10'];
        yield 'cloud metadata' => ['https://169.254.169.254', '169.254.169.254'];
        yield 'unique local IPv6' => ['https://[fd00::1]', '[fd00::1]'];
        yield 'this host' => ['https://0.0.0.0', '0.0.0.0'];
    }

    /**
     * @return list<\App\Application\Integration\ExternalSale>
     */
    private static function orders(HttpClientInterface $client, string $address): array
    {
        return iterator_to_array((new WebsiteApiGateway($client, new WebsitePayloadMapper()))->paidOrders(new WebsiteAccess($address, 'integration-token', 'Website')), false);
    }
}
