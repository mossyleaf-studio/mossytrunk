<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\ExternalSale;

interface WebsiteGateway
{
    /**
     * @return iterable<ExternalSale>
     */
    public function paidOrders(WebsiteAccess $access): iterable;
}
