<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\CatalogueChanges;
use App\Application\Integration\ExternalSale;
use App\Application\Integration\StockedProduct;

interface WebsiteGateway
{
    /**
     * @return iterable<ExternalSale>
     */
    public function paidOrders(WebsiteAccess $access): iterable;

    /**
     * @param list<StockedProduct> $products
     */
    public function replaceCatalogue(WebsiteAccess $access, array $products): CatalogueChanges;
}
