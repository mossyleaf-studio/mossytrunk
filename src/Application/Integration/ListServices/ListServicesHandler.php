<?php

declare(strict_types=1);

namespace App\Application\Integration\ListServices;

use App\Application\Integration\AuthorizingConnector;
use App\Application\Integration\CatalogueExporting;
use App\Application\Integration\CatalogueImporting;
use App\Application\Integration\CataloguePublishing;
use App\Application\Integration\CatalogueReading;
use App\Application\Integration\ConnectionSecrets;
use App\Application\Integration\Connectors;
use App\Application\Integration\ReferencePublishing;
use App\Application\Integration\SalesConnector;
use App\Application\Integration\ServiceField;
use App\Domain\Integration\ExternalItemRepository;
use App\Domain\Integration\ServiceConnection;
use App\Domain\Integration\ServiceConnectionRepository;

final readonly class ListServicesHandler
{
    public function __construct(
        private Connectors $connectors,
        private ServiceConnectionRepository $connections,
        private ExternalItemRepository $items,
        private ConnectionSecrets $secrets,
    ) {
    }

    /**
     * @return list<ServiceView>
     */
    public function __invoke(): array
    {
        $connections = [];
        foreach ($this->connections->all() as $connection) {
            $connections[$connection->service()] = $connection;
        }

        return array_map(
            fn (SalesConnector $connector): ServiceView => $this->view($connector, $connections[$connector->describe()->key] ?? null),
            $this->connectors->all(),
        );
    }

    private function view(SalesConnector $connector, ?ServiceConnection $connection): ServiceView
    {
        $description = $connector->describe();

        return new ServiceView(
            $description->key,
            $description->label,
            $description->summary,
            $description->instructions,
            $connector instanceof AuthorizingConnector,
            $connector instanceof CatalogueExporting,
            $connector instanceof CatalogueImporting,
            $connector instanceof CatalogueReading,
            $connector instanceof ReferencePublishing,
            $connector instanceof CataloguePublishing,
            array_map(static fn (ServiceField $field): FieldView => new FieldView($field->name, $field->label, $field->secret, $field->required, $field->hint, $field->maxLength), $description->fields),
            $description->defaultSalesContext->value,
            $description->defaultUnknownItems->value,
            null === $connection ? null : $this->connectionView($connector, $connection),
        );
    }

    private function connectionView(SalesConnector $connector, ServiceConnection $connection): ConnectionView
    {
        $values = [];
        $configured = true;
        foreach ($connector->describe()->fields as $field) {
            if ($field->secret) {
                $secret = $this->secrets->reveal($connection, $field->name);
                $values[$field->name] = new FieldValueView(null, null !== $secret, null === $secret ? null : '••••'.mb_substr($secret, -4));
                $known = null !== $secret;
            } else {
                $value = $connection->setting($field->name);
                $values[$field->name] = new FieldValueView($value, null !== $value, null);
                $known = null !== $value;
            }
            $configured = $configured && (!$field->required || $known);
        }

        return new ConnectionView(
            $values,
            $connection->salesContext()->value,
            $connection->unknownItems()->value,
            $configured,
            $connection->isAuthorized(),
            $connection->accountName(),
            $this->items->unlinkedCount($connection->service()),
        );
    }
}
