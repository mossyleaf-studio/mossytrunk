<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\Exception\AccessRefused;
use App\Application\Integration\Exception\IntegrationDisabled;
use App\Application\Integration\Exception\ServiceUnavailable;
use App\Application\Integration\Exception\ServiceUnreachable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WebsiteApiGateway implements WebsiteGateway
{
    private const int TIMEOUT = 20;
    private const int MAX_DURATION = 60;
    private const int MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        #[Autowire(service: 'app.website.client')]
        private HttpClientInterface $client,
        private WebsitePayloadMapper $mapper,
    ) {
    }

    public function paidOrders(WebsiteAccess $access): iterable
    {
        try {
            $response = $this->client->request('GET', $access->address.'/integrations/orders', [
                'auth_bearer' => $access->token,
                'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'],
                'on_progress' => self::stopPastTheLimit(...),
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ]);
            $status = $response->getStatusCode();
            if (Response::HTTP_OK !== $status) {
                throw self::refusal($access, $status);
            }

            return $this->mapper->sales($response->toArray()['orders'] ?? null);
        } catch (ExceptionInterface|\UnexpectedValueException) {
            throw new ServiceUnreachable($access->label, $access->host());
        }
    }

    private static function stopPastTheLimit(int $downloaded): void
    {
        if ($downloaded > self::MAX_BYTES) {
            throw new \LengthException('The website answered more than an import reads.');
        }
    }

    private static function refusal(WebsiteAccess $access, int $status): ServiceUnavailable
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN => new AccessRefused($access->label),
            Response::HTTP_NOT_FOUND => new IntegrationDisabled($access->label),
            default => new ServiceUnreachable($access->label, 'HTTP '.$status),
        };
    }
}
