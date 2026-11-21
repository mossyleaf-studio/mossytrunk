<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

use App\Application\Integration\ConnectionSession;
use App\Application\Integration\Connectors;
use App\Application\Integration\DescribedListing;
use App\Application\Integration\DescriptionReading;
use App\Application\Integration\Exception\ServiceUnavailable;
use App\Application\Integration\ImportSales\ExternalItemResolution;
use App\Domain\Integration\ExternalItemRepository;
use App\Domain\Integration\ServiceConnectionRepository;

final readonly class ProductDescriptions
{
    public function __construct(
        private Connectors $connectors,
        private ServiceConnectionRepository $connections,
        private ConnectionSession $session,
        private ExternalItemRepository $items,
        private ExternalItemResolution $resolution,
    ) {
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function known(): array
    {
        $known = [];
        foreach ($this->connectors->describing() as $connector) {
            $known += $this->describedBy($connector);
        }

        return $known;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function describedBy(DescriptionReading $connector): array
    {
        $service = $connector->describe()->key;
        $connection = $this->connections->find($service);
        if (null === $connection) {
            return [];
        }
        try {
            $listings = iterator_to_array($connector->describedListings($this->session->credentials($connection)), false);
        } catch (ServiceUnavailable) {
            return [];
        }
        usort($listings, static fn (DescribedListing $a, DescribedListing $b): int => strnatcmp($a->externalRef, $b->externalRef));

        $products = new ListingProducts($this->items->ofService($service), $this->resolution->catalogue());
        $known = [];
        foreach ($listings as $listing) {
            $product = [] === $listing->descriptions ? null : $products->productOf($listing);
            if (null !== $product) {
                $known[$product] ??= $listing->descriptions;
            }
        }

        return $known;
    }
}
