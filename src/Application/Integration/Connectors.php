<?php

declare(strict_types=1);

namespace App\Application\Integration;

use App\Application\Integration\Exception\ServiceNotAuthorizing;
use App\Application\Integration\Exception\ServiceNotExportingCatalogue;
use App\Application\Integration\Exception\ServiceNotImportingCatalogue;
use App\Application\Integration\Exception\ServiceNotPublishingCatalogue;
use App\Application\Integration\Exception\ServiceNotPublishingReferences;
use App\Application\Integration\Exception\ServiceNotReadingCatalogue;
use App\Application\Integration\Exception\ServiceNotRegistered;
use App\Application\Translator;
use App\Domain\Order\Order;

final readonly class Connectors
{
    /** @var array<string, SalesConnector> */
    private array $connectors;

    /**
     * @param iterable<SalesConnector> $connectors
     */
    public function __construct(iterable $connectors, private Translator $translator)
    {
        $byKey = [];
        foreach ($connectors as $connector) {
            $byKey[$connector->describe()->key] = $connector;
        }
        ksort($byKey);
        $this->connectors = $byKey;
    }

    public function get(string $service): SalesConnector
    {
        return $this->connectors[$service] ?? throw new ServiceNotRegistered($service);
    }

    public function authorizing(string $service): AuthorizingConnector
    {
        $connector = $this->get($service);
        if (!$connector instanceof AuthorizingConnector) {
            throw new ServiceNotAuthorizing($connector->describe()->label);
        }

        return $connector;
    }

    public function exporting(string $service): CatalogueExporting
    {
        $connector = $this->get($service);
        if (!$connector instanceof CatalogueExporting) {
            throw new ServiceNotExportingCatalogue($connector->describe()->label);
        }

        return $connector;
    }

    public function importing(string $service): CatalogueImporting
    {
        $connector = $this->get($service);
        if (!$connector instanceof CatalogueImporting) {
            throw new ServiceNotImportingCatalogue($connector->describe()->label);
        }

        return $connector;
    }

    public function reading(string $service): CatalogueReading
    {
        $connector = $this->get($service);
        if (!$connector instanceof CatalogueReading) {
            throw new ServiceNotReadingCatalogue($connector->describe()->label);
        }

        return $connector;
    }

    public function publishing(string $service): ReferencePublishing
    {
        $connector = $this->get($service);
        if (!$connector instanceof ReferencePublishing) {
            throw new ServiceNotPublishingReferences($connector->describe()->label);
        }

        return $connector;
    }

    public function cataloguePublishing(string $service): CataloguePublishing
    {
        $connector = $this->get($service);
        if (!$connector instanceof CataloguePublishing) {
            throw new ServiceNotPublishingCatalogue($connector->describe()->label);
        }

        return $connector;
    }

    /**
     * @return list<DescriptionReading>
     */
    public function describing(): array
    {
        $describing = [];
        foreach ($this->connectors as $connector) {
            if ($connector instanceof DescriptionReading) {
                $describing[] = $connector;
            }
        }

        return $describing;
    }

    public function has(string $service): bool
    {
        return isset($this->connectors[$service]);
    }

    /**
     * @return list<SalesConnector>
     */
    public function all(): array
    {
        return array_values($this->connectors);
    }

    public function labelOf(string $source): string
    {
        if (Order::MANUAL === $source) {
            return $this->translator->trans('order.source.manual');
        }

        return isset($this->connectors[$source]) ? $this->connectors[$source]->describe()->label : $source;
    }
}
