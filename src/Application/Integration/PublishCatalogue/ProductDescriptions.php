<?php

declare(strict_types=1);

namespace App\Application\Integration\PublishCatalogue;

use App\Application\Integration\ConnectionSession;
use App\Application\Integration\Connectors;
use App\Application\Integration\DescriptionReading;
use App\Application\Integration\Exception\ServiceNotConfigured;
use App\Application\Integration\Exception\ServiceNotConnected;
use App\Application\Integration\Exception\ServiceUnavailable;
use App\Application\Integration\ImportSales\ExternalItemResolution;
use App\Application\Integration\StockedProduct;
use App\Domain\Integration\ExternalItemRepository;
use App\Domain\Integration\ServiceConnection;
use App\Domain\Integration\ServiceConnectionRepository;
use Psr\Log\LoggerInterface;

final readonly class ProductDescriptions
{
    public function __construct(
        private Connectors $connectors,
        private ServiceConnectionRepository $connections,
        private ConnectionSession $session,
        private ExternalItemRepository $items,
        private ExternalItemResolution $resolution,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<StockedProduct> $products
     */
    public function of(array $products): DescribedCatalogue
    {
        $catalogue = new DescribedCatalogue($products);
        foreach ($this->connectors->describing() as $connector) {
            $connection = $this->connections->find($connector->describe()->key);
            $texts = null === $connection ? null : $this->texts($connector, $connection);
            if (null !== $texts) {
                $catalogue = $catalogue->describedBy($connector->describe()->label, $texts);
            }
        }

        return $catalogue;
    }

    private function texts(DescriptionReading $connector, ServiceConnection $connection): ?ListingTexts
    {
        try {
            $listings = iterator_to_array($connector->describedListings($this->session->credentials($connection)), false);
        } catch (ServiceNotConnected|ServiceNotConfigured) {
            return null;
        } catch (ServiceUnavailable $failure) {
            $this->logger->warning('The {service} listings could not be read for the descriptions.', ['service' => $connection->service(), 'failure' => $failure::class]);

            return ListingTexts::unread();
        }

        return ListingTexts::read($listings, new ListingProducts($this->items->ofService($connection->service()), $this->resolution->catalogue()));
    }
}
