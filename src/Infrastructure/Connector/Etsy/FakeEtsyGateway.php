<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

use App\Application\Integration\ExternalSale;
use App\Application\Integration\Tokens;
use App\Infrastructure\Http\Json;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FakeEtsyGateway implements EtsyGateway
{
    /** @var list<ExternalSale>|null */
    private ?array $receipts = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $listings = null;

    /** @var array<string, array<string, mixed>> */
    private array $updates = [];

    public function __construct(
        private readonly EtsyPayloadMapper $mapper,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/tests/Fixtures/etsy/receipts.json')]
        private readonly string $fixture,
        #[Autowire('%kernel.project_dir%/tests/Fixtures/etsy/listings.json')]
        private readonly string $listingsFixture,
        #[Autowire('%kernel.project_dir%/tests/Fixtures/etsy/ledger.json')]
        private readonly string $ledgerFixture,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $listings
     */
    public function willList(array $listings): void
    {
        $this->listings = [];
        foreach ($listings as $listing) {
            $this->listings[Json::string($listing['listing_id'] ?? '')] = $listing;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function updates(): array
    {
        return $this->updates;
    }

    /**
     * @param list<ExternalSale> $receipts
     */
    public function willReturn(array $receipts): void
    {
        $this->receipts = $receipts;
    }

    public function authorizationUrl(EtsyApp $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return $redirectUri.'?'.http_build_query(['code' => 'fake-code', 'state' => $state]);
    }

    public function exchangeCode(EtsyApp $app, string $code, string $codeVerifier, string $redirectUri): Tokens
    {
        return new Tokens('fake-access', 'fake-refresh', $this->clock->now()->modify('+1 hour'));
    }

    public function refresh(EtsyApp $app, string $refreshToken): Tokens
    {
        return new Tokens('fake-access-renewed', 'fake-refresh', $this->clock->now()->modify('+1 hour'));
    }

    public function shop(EtsyApp $app, string $accessToken): EtsyShop
    {
        return new EtsyShop('12345678', 'Atelier Mousse sur Etsy');
    }

    public function paidReceipts(EtsyApp $app, string $accessToken, string $shopId): iterable
    {
        if (null !== $this->receipts) {
            return $this->receipts;
        }

        $receipts = Json::objects(Json::object(json_decode((string) file_get_contents($this->fixture), true, flags: \JSON_THROW_ON_ERROR))['results'] ?? []);
        $ledger = Json::object(json_decode((string) file_get_contents($this->ledgerFixture), true, flags: \JSON_THROW_ON_ERROR));
        $fees = new EtsyFees($receipts, Json::objects($ledger['entries'] ?? []), Json::objects($ledger['payments'] ?? []));

        return array_map(fn (array $receipt): ExternalSale => $this->mapper->sale($receipt, $fees->of(Json::string($receipt['receipt_id'] ?? ''))), $receipts);
    }

    public function activeListings(EtsyApp $app, string $accessToken, string $shopId): iterable
    {
        return array_values($this->listed());
    }

    public function inventory(EtsyApp $app, string $accessToken, string $listingId): array
    {
        return Json::object($this->listed()[$listingId]['inventory'] ?? []);
    }

    public function updateInventory(EtsyApp $app, string $accessToken, string $listingId, array $inventory): void
    {
        $this->updates[$listingId] = $inventory;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function listed(): array
    {
        if (null === $this->listings) {
            $this->willList(Json::objects(Json::object(json_decode((string) file_get_contents($this->listingsFixture), true, flags: \JSON_THROW_ON_ERROR))['results'] ?? []));
        }

        return $this->listings ?? [];
    }
}
