<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Connector;

use App\Application\Integration\Exception\ServiceUnreachable;
use App\Infrastructure\Connector\Website\WebsiteAccess;
use App\Infrastructure\Connector\Website\WebsiteApiGateway;
use App\Tests\Support\DomainExceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class WebsiteClientWiringTest extends KernelTestCase
{
    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            $this->requested[] = $url;

            return new JsonMockResponse(['orders' => []]);
        }));
    }

    #[DataProvider('privateAddresses')]
    public function testTheGatewayOfTheContainerNeverRequestsAPrivateAddress(string $address, string $host): void
    {
        DomainExceptions::assertThrown(new ServiceUnreachable('Website', $host), static fn (): iterable => self::orders($address));

        self::assertSame([], $this->requested);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function privateAddresses(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1', '127.0.0.1'];
        yield 'private class A' => ['https://10.0.0.5', '10.0.0.5'];
        yield 'private class B' => ['https://172.16.4.2', '172.16.4.2'];
        yield 'private class C' => ['https://192.168.1.10', '192.168.1.10'];
    }

    public function testTheGatewayOfTheContainerRequestsAPublicAddressThroughTheClientOfTheApplication(): void
    {
        self::assertSame([], self::orders('https://93.184.216.34'));

        self::assertSame(['https://93.184.216.34/integrations/orders'], $this->requested);
    }

    /**
     * @return iterable<\App\Application\Integration\ExternalSale>
     */
    private static function orders(string $address): iterable
    {
        return self::getContainer()->get(WebsiteApiGateway::class)->paidOrders(new WebsiteAccess($address, 'integration-token', 'Website'));
    }
}
