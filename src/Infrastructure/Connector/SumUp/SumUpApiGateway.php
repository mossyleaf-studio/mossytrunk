<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\SumUp;

use App\Application\Integration\Exception\ServiceUnreachable;
use App\Infrastructure\Http\Json;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class SumUpApiGateway implements SumUpGateway
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        #[Target('sumup.client')]
        private HttpClientInterface $client,
        private SumUpPayloadMapper $mapper,
    ) {
    }

    public function successfulPayments(SumUpCredentials $credentials): iterable
    {
        try {
            $query = http_build_query(['order' => 'ascending', 'limit' => self::PAGE_SIZE, 'statuses[]' => 'SUCCESSFUL', 'types[]' => 'PAYMENT']);

            while (null !== $query) {
                $page = $this->get($credentials, \sprintf('/v2.1/merchants/%s/transactions/history?%s', rawurlencode($credentials->merchantCode), $query));

                $details = [];
                foreach (Json::objects($page['items'] ?? []) as $item) {
                    if ($this->mapper->paidOnline($item)) {
                        continue;
                    }
                    $details[] = [$item, $this->request($credentials, \sprintf('/v2.1/merchants/%s/transactions?%s', rawurlencode($credentials->merchantCode), http_build_query(['id' => Json::string($item['id'] ?? '')])))];
                }

                foreach ($details as [$item, $response]) {
                    $transaction = Json::object($response->toArray()) + $item;
                    if (!$this->mapper->paidOnline($transaction)) {
                        yield $this->mapper->sale($transaction);
                    }
                }

                $query = self::nextPageQuery($page);
            }
        } catch (ExceptionInterface $exception) {
            throw new ServiceUnreachable('SumUp', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get(SumUpCredentials $credentials, string $url): array
    {
        return Json::object($this->request($credentials, $url)->toArray());
    }

    private function request(SumUpCredentials $credentials, string $url): ResponseInterface
    {
        return $this->client->request('GET', $url, ['auth_bearer' => $credentials->apiKey]);
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function nextPageQuery(array $page): ?string
    {
        foreach (Json::objects($page['links'] ?? []) as $link) {
            $href = Json::string($link['href'] ?? '');
            if ('next' === ($link['rel'] ?? null) && '' !== $href) {
                return ltrim($href, '?');
            }
        }

        return null;
    }
}
