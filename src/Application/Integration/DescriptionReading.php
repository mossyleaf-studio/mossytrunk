<?php

declare(strict_types=1);

namespace App\Application\Integration;

interface DescriptionReading extends SalesConnector
{
    /**
     * @return iterable<DescribedListing>
     */
    public function describedListings(Credentials $credentials): iterable;
}
