<?php

declare(strict_types=1);

namespace App\Application\Integration;

interface CataloguePublishing extends SalesConnector
{
    /**
     * @param list<StockedProduct> $products
     */
    public function publishCatalogue(Credentials $credentials, array $products): CatalogueChanges;
}
