<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

use App\Application\Integration\ExternalSale;
use App\Application\Integration\Tokens;

interface EtsyGateway
{
    public function authorizationUrl(EtsyApp $app, string $redirectUri, string $state, string $codeChallenge): string;

    public function exchangeCode(EtsyApp $app, string $code, string $codeVerifier, string $redirectUri): Tokens;

    public function refresh(EtsyApp $app, string $refreshToken): Tokens;

    public function shop(EtsyApp $app, string $accessToken): EtsyShop;

    /**
     * @return iterable<ExternalSale>
     */
    public function paidReceipts(EtsyApp $app, string $accessToken, string $shopId): iterable;

    /**
     * @return iterable<array<string, mixed>>
     */
    public function activeListings(EtsyApp $app, string $accessToken, string $shopId): iterable;

    /**
     * @return iterable<array<string, mixed>>
     */
    public function describedListings(EtsyApp $app, string $accessToken, string $shopId): iterable;

    /**
     * @return array<string, mixed>
     */
    public function inventory(EtsyApp $app, string $accessToken, string $listingId): array;

    /**
     * @param array<string, mixed> $inventory
     */
    public function updateInventory(EtsyApp $app, string $accessToken, string $listingId, array $inventory): void;
}
