<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

use App\Application\Integration\Exception\AccountWithoutShop;
use App\Application\Integration\Exception\MissingPermission;
use App\Application\Integration\Exception\ServiceUnreachable;
use App\Application\Integration\ExternalSale;
use App\Application\Integration\Tokens;
use App\Infrastructure\Http\Json;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class EtsyApiGateway implements EtsyGateway
{
    private const int PAGE_SIZE = 100;
    private const int LEDGER_WINDOW = 30 * 86_400;
    private const string SCOPES = 'transactions_r shops_r listings_r listings_w';

    public function __construct(
        #[Target('etsy.client')]
        private HttpClientInterface $client,
        private EtsyPayloadMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function authorizationUrl(EtsyApp $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://www.etsy.com/oauth/connect?'.http_build_query([
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'client_id' => $app->keystring,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public function exchangeCode(EtsyApp $app, string $code, string $codeVerifier, string $redirectUri): Tokens
    {
        return $this->tokens($app, ['grant_type' => 'authorization_code', 'client_id' => $app->keystring, 'redirect_uri' => $redirectUri, 'code' => $code, 'code_verifier' => $codeVerifier]);
    }

    public function refresh(EtsyApp $app, string $refreshToken): Tokens
    {
        return $this->tokens($app, ['grant_type' => 'refresh_token', 'client_id' => $app->keystring, 'refresh_token' => $refreshToken]);
    }

    public function shop(EtsyApp $app, string $accessToken): EtsyShop
    {
        $shopId = Json::string($this->get($app, $accessToken, '/v3/application/users/me')['shop_id'] ?? '');
        if ('' === $shopId) {
            throw new AccountWithoutShop('Etsy');
        }

        return new EtsyShop($shopId, Json::string($this->get($app, $accessToken, '/v3/application/shops/'.rawurlencode($shopId))['shop_name'] ?? $shopId));
    }

    public function paidReceipts(EtsyApp $app, string $accessToken, string $shopId): iterable
    {
        $receipts = iterator_to_array($this->pages($app, $accessToken, \sprintf('/v3/application/shops/%s/receipts', rawurlencode($shopId)), ['was_paid' => 'true']), false);
        if ([] === $receipts) {
            return [];
        }

        $entries = $this->ledgerEntries($app, $accessToken, $shopId, min(array_map(static fn (array $receipt): int => (int) Json::number($receipt['create_timestamp'] ?? $receipt['created_timestamp'] ?? 0), $receipts)));
        $fees = new EtsyFees($receipts, $entries, $this->payments($app, $accessToken, $shopId, $entries));

        return array_map(fn (array $receipt): ExternalSale => $this->mapper->sale($receipt, $fees->of(Json::string($receipt['receipt_id'] ?? ''))), $receipts);
    }

    public function activeListings(EtsyApp $app, string $accessToken, string $shopId): iterable
    {
        return $this->pages($app, $accessToken, \sprintf('/v3/application/shops/%s/listings', rawurlencode($shopId)), ['state' => 'active', 'includes' => 'Inventory']);
    }

    public function inventory(EtsyApp $app, string $accessToken, string $listingId): array
    {
        return $this->get($app, $accessToken, \sprintf('/v3/application/listings/%s/inventory', rawurlencode($listingId)));
    }

    public function updateInventory(EtsyApp $app, string $accessToken, string $listingId, array $inventory): void
    {
        $this->call($app, $accessToken, 'PUT', \sprintf('/v3/application/listings/%s/inventory', rawurlencode($listingId)), ['json' => $inventory]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerEntries(EtsyApp $app, string $accessToken, string $shopId, int $since): array
    {
        $entries = [];
        $now = $this->clock->now()->getTimestamp();
        for ($from = $since; $from <= $now; $from += self::LEDGER_WINDOW) {
            array_push($entries, ...$this->pages($app, $accessToken, \sprintf('/v3/application/shops/%s/payment-account/ledger-entries', rawurlencode($shopId)), ['min_created' => $from, 'max_created' => min($now, $from + self::LEDGER_WINDOW - 1)]));
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function payments(EtsyApp $app, string $accessToken, string $shopId, array $entries): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $entry): string => Json::string($entry['reference_id'] ?? ''),
            array_filter($entries, static fn (array $entry): bool => 'payment' === strtolower(Json::string($entry['reference_type'] ?? ''))),
        )));

        $payments = [];
        foreach (array_chunk($ids, self::PAGE_SIZE) as $chunk) {
            array_push($payments, ...Json::objects($this->get($app, $accessToken, \sprintf('/v3/application/shops/%s/payments?%s', rawurlencode($shopId), http_build_query(['payment_ids' => implode(',', $chunk)])))['results'] ?? []));
        }

        return $payments;
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(EtsyApp $app, string $accessToken, string $path, array $query): \Generator
    {
        for ($offset = 0;; $offset += self::PAGE_SIZE) {
            $results = Json::objects($this->get($app, $accessToken, $path.'?'.http_build_query($query + ['limit' => self::PAGE_SIZE, 'offset' => $offset]))['results'] ?? []);
            yield from $results;
            if (\count($results) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * @param array<string, string> $form
     */
    private function tokens(EtsyApp $app, array $form): Tokens
    {
        try {
            $payload = Json::object($this->client->request('POST', '/v3/public/oauth/token', ['body' => $form, 'headers' => ['x-api-key' => self::apiKey($app)]])->toArray());
        } catch (ExceptionInterface $exception) {
            throw new ServiceUnreachable('Etsy', $exception->getMessage());
        }

        return new Tokens(
            Json::string($payload['access_token'] ?? ''),
            Json::string($payload['refresh_token'] ?? ''),
            $this->clock->now()->modify(\sprintf('+%d seconds', (int) Json::number($payload['expires_in'] ?? 3600))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get(EtsyApp $app, string $accessToken, string $url): array
    {
        return $this->call($app, $accessToken, 'GET', $url);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function call(EtsyApp $app, string $accessToken, string $method, string $url, array $options = []): array
    {
        try {
            return Json::object($this->client->request($method, $url, ['auth_bearer' => $accessToken, 'headers' => ['x-api-key' => self::apiKey($app)]] + $options)->toArray());
        } catch (ClientExceptionInterface $exception) {
            if (Response::HTTP_FORBIDDEN === $exception->getResponse()->getStatusCode()) {
                throw new MissingPermission('Etsy');
            }
            throw new ServiceUnreachable('Etsy', $exception->getMessage());
        } catch (ExceptionInterface $exception) {
            throw new ServiceUnreachable('Etsy', $exception->getMessage());
        }
    }

    private static function apiKey(EtsyApp $app): string
    {
        return $app->keystring.':'.$app->sharedSecret;
    }
}
