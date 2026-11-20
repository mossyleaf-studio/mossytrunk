<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

final class CatalogueRefused extends ServiceUnavailable
{
    public function __construct(string $label, string $reason)
    {
        parent::__construct('integration.catalogue_refused', ['service' => $label, 'reason' => $reason]);
    }
}
